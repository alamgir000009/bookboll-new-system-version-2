<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessAssignmentPreview;
use App\Models\BusinessMembership;
use App\Models\User;
use App\Models\Venue;
use App\Support\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Moving an account into a business: options, a reviewed preview, then commit.
 *
 * Three endpoints rather than one because this is destructive. The move strips
 * whatever platform access the account had — roles, jurisdiction, venue,
 * listing and program grants, custom permission overrides — and replaces it
 * with a single business membership. An admin sees exactly what disappears
 * before anything happens.
 *
 * The commit is atomic and re-checked: a token proves which move was reviewed,
 * everything is reauthorised and recomputed inside the transaction, and a stale
 * or replayed token is refused rather than applied on an old review.
 */
class BusinessAssignmentService
{
    public const CONTRACT_VERSION = 1;

    /** A review is good for fifteen minutes. */
    public const PREVIEW_TTL_MINUTES = 15;

    /* ─────────────────────────────── options ─────────────────────────────── */

    public function options(Request $request, int $businessId)
    {
        [$business, $actor, $error] = $this->resolve($request, $businessId);
        if ($error) {
            return $error;
        }

        $roles = BusinessMembership::ASSIGNABLE_ROLES;

        // The owner seat is not assignable while it is taken. Offering it would
        // promise something the one-active-owner constraint will refuse.
        if ($business->ownerMembership()->exists() || $business->owner_id) {
            $roles = array_values(array_diff($roles, [BusinessMembership::ROLE_OWNER]));
        }

        return sendSuccess('Assignment options', [
            'contract_version' => self::CONTRACT_VERSION,
            'can_assign'       => true,
            'roles'            => array_values($roles),
            'candidates'       => $this->candidates($actor),
            'venues'           => $this->assignableVenues($business),
        ]);
    }

    /**
     * Accounts this actor may move.
     *
     * Deliberately not a user directory: only id, name and email, only active
     * accounts, never anyone already in a business, and never the actor. A
     * country admin sees only their own jurisdiction.
     */
    protected function candidates(User $actor): array
    {
        $query = User::query()
            ->where('status', User::ACTIVE)
            ->where('id', '!=', $actor->id)
            ->whereDoesntHave('businessMemberships', function ($q) {
                $q->where('status', BusinessMembership::STATUS_ACTIVE);
            });

        if (! AccessScope::isGlobal($actor)) {
            $countries = AccessScope::jurisdictionCountryIds($actor);
            if (is_array($countries)) {
                $query->whereIn('country_id', $countries ?: [0]);
            }
        }

        return $query->orderBy('name')->limit(200)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id'    => (string) $u->id,
                'name'  => $u->name ?: '',
                'email' => $u->email ?: '',
            ])->all();
    }

    /** @return array<int, array{id:string,name:string}> */
    protected function assignableVenues(Business $business): array
    {
        $ownerId = $business->owner_id;

        return Venue::query()
            ->when($ownerId, fn ($q) => $q->where('owner_user_id', $ownerId))
            ->when(! $ownerId, fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Venue $v) => ['id' => (string) $v->id, 'name' => $v->name ?: ''])
            ->all();
    }

    /* ─────────────────────────────── preview ─────────────────────────────── */

    public function preview(Request $request, int $businessId)
    {
        [$business, $actor, $error] = $this->resolve($request, $businessId);
        if ($error) {
            return $error;
        }

        $data = $request->validate([
            'user_id'        => 'required|integer|exists:users,id',
            'role'           => ['required', 'string', Rule::in(BusinessMembership::ASSIGNABLE_ROLES)],
            'scope'          => ['required', Rule::in(['all', 'selected'])],
            'location_ids'   => 'nullable|array',
            'location_ids.*' => 'integer',
        ]);

        $target = User::find($data['user_id']);
        $assessment = $this->assess($business, $actor, $target, $data);

        $preview = BusinessAssignmentPreview::create([
            'token'          => 'bap_'.Str::random(56),
            'business_id'    => $business->id,
            'actor_user_id'  => $actor->id,
            'target_user_id' => $target->id,
            'role'           => $data['role'],
            'scope'          => $data['scope'],
            'location_ids'   => $data['scope'] === 'selected' ? array_values($data['location_ids'] ?? []) : null,
            'warnings'       => $assessment['warnings'],
            'blockers'       => $assessment['blockers'],
            'state_hash'     => $this->stateHash($business, $target),
            'expires_at'     => now()->addMinutes(self::PREVIEW_TTL_MINUTES),
        ]);

        return sendSuccess('Assignment preview', [
            'contract_version' => self::CONTRACT_VERSION,
            'preview_token'    => $preview->token,
            'expires_at'       => $preview->expires_at->utc()->toIso8601String(),
            'business_id'      => (string) $business->id,
            'user_id'          => (string) $target->id,
            'user_name'        => $target->name ?: '',
            'user_email'       => $target->email ?: '',
            'role'             => $data['role'],
            'scope_label'      => $this->scopeLabel($data, $business),
            'warnings'         => $assessment['warnings'],
            'blockers'         => $assessment['blockers'],
        ]);
    }

    /**
     * What this move would do, and what stops it.
     *
     * Warnings are things the admin must see happen; blockers prevent the
     * commit entirely. Both are recomputed at commit, so a review cannot
     * authorise a move whose circumstances have since changed.
     *
     * @return array{warnings: array<int,string>, blockers: array<int,string>}
     */
    protected function assess(Business $business, User $actor, ?User $target, array $data): array
    {
        $warnings = [];
        $blockers = [];

        if (! $target) {
            return ['warnings' => [], 'blockers' => ['That account no longer exists.']];
        }

        // Protecting the actor: nobody strips their own platform access, which
        // would lock them out mid-operation.
        if ((int) $target->id === (int) $actor->id) {
            $blockers[] = 'You cannot assign your own account to a business.';
        }

        if ($target->status !== User::ACTIVE) {
            $blockers[] = 'That account is not active.';
        }

        $existing = BusinessMembership::where('user_id', $target->id)
            ->where('status', BusinessMembership::STATUS_ACTIVE)->first();
        if ($existing) {
            $blockers[] = $existing->business_id === $business->id
                ? 'That account already belongs to this business.'
                : 'That account already belongs to another business. Remove it there first.';
        }

        // The last active superadmin must not be moved out of the platform.
        if ($target->hasRole('super_admin') && $this->activeSuperAdmins() <= 1) {
            $blockers[] = 'This is the last active super admin and cannot be moved.';
        }

        if ($data['scope'] === 'selected' && empty($data['location_ids'])) {
            $blockers[] = 'Choose at least one venue for a limited scope.';
        }

        if ($data['scope'] === 'selected') {
            $allowed = collect($this->assignableVenues($business))->pluck('id')->map('intval')->all();
            $foreign = array_diff(array_map('intval', $data['location_ids'] ?? []), $allowed);
            if ($foreign) {
                $blockers[] = 'Some selected venues do not belong to this business.';
            }
        }

        // Everything below is lost by the move. readmeAlamgir: "Return
        // inherited permissions that will be removed in warnings."
        foreach ($target->getRoleNames() as $role) {
            $warnings[] = "Platform role \"{$role}\" will be removed.";
        }
        if (! empty($target->assigned_country_ids)) {
            $warnings[] = 'Assigned countries will be cleared.';
        }
        foreach ([
            'assigned_location_ids' => 'venue',
            'assigned_listing_ids'  => 'listing',
            'assigned_program_ids'  => 'programme',
        ] as $column => $label) {
            if (! empty($target->{$column})) {
                $warnings[] = "Existing {$label} assignments will be cleared.";
            }
        }
        if (! empty($target->custom_permissions)) {
            $warnings[] = 'Custom platform permissions will be removed.';
        }
        $warnings[] = 'Active sessions will be signed out so the new access takes effect.';

        return ['warnings' => array_values($warnings), 'blockers' => array_values($blockers)];
    }

    protected function activeSuperAdmins(): int
    {
        return User::role('super_admin')->where('status', User::ACTIVE)->count();
    }

    protected function scopeLabel(array $data, Business $business): string
    {
        if ($data['scope'] !== 'selected') {
            return 'All venues in ' . ($business->name ?: 'this business');
        }

        $n = count($data['location_ids'] ?? []);

        return $n === 1 ? '1 selected venue' : "{$n} selected venues";
    }

    /**
     * A fingerprint of everything the review depended on. Recomputed at commit:
     * if the target's roles, status or business have moved since, the token no
     * longer describes the move being asked for.
     */
    protected function stateHash(Business $business, User $target): string
    {
        return hash('sha256', json_encode([
            'business'   => $business->id,
            'owner'      => $business->owner_id,
            'target'     => $target->id,
            'status'     => $target->status,
            'roles'      => $target->getRoleNames()->sort()->values()->all(),
            'membership' => BusinessMembership::where('user_id', $target->id)
                ->where('status', BusinessMembership::STATUS_ACTIVE)->value('business_id'),
        ]));
    }

    /* ──────────────────────────────── commit ─────────────────────────────── */

    public function assign(Request $request, int $businessId)
    {
        [$business, $actor, $error] = $this->resolve($request, $businessId);
        if ($error) {
            return $error;
        }

        $data = $request->validate([
            'preview_token'   => 'required|string|max:80',
            'idempotency_key' => 'required|string|max:120',
        ]);

        // A retry of a completed move returns what it produced the first time.
        $done = BusinessAssignmentPreview::where('idempotency_key', $data['idempotency_key'])
            ->whereNotNull('membership_id')->first();
        if ($done) {
            return sendSuccess('Account assigned', [
                'contract_version' => self::CONTRACT_VERSION,
                'membership'       => $this->mapMembership(BusinessMembership::find($done->membership_id)),
            ]);
        }

        return DB::transaction(function () use ($data, $business, $actor) {
            // Lock the review row so two commits cannot consume it at once.
            $preview = BusinessAssignmentPreview::where('token', $data['preview_token'])
                ->lockForUpdate()->first();

            if (! $preview) {
                return sendError('That review could not be found. Please review the change again.', [], 409);
            }
            // Replay across businesses, actors or targets is refused: the token
            // is only valid for the exact move it was minted for.
            if ((int) $preview->business_id !== (int) $business->id
                || (int) $preview->actor_user_id !== (int) $actor->id) {
                return sendError('That review does not belong to this request.', [], 409);
            }
            if ($preview->isConsumed()) {
                return sendError('That review has already been used. Please review the change again.', [], 409);
            }
            if ($preview->isExpired()) {
                return sendError('That review has expired. Please review the change again.', [], 409);
            }

            $target = User::whereKey($preview->target_user_id)->lockForUpdate()->first();
            if (! $target) {
                return sendError('That account no longer exists.', [], 409);
            }

            // Reauthorise and recompute rather than trusting the review.
            if ($this->stateHash($business, $target) !== $preview->state_hash) {
                return sendError('This account has changed since it was reviewed. Please review it again.', [], 409);
            }

            $recheck = $this->assess($business, $actor, $target, [
                'role'         => $preview->role,
                'scope'        => $preview->scope,
                'location_ids' => $preview->location_ids ?? [],
            ]);
            if ($recheck['blockers']) {
                return sendError($recheck['blockers'][0], ['blockers' => $recheck['blockers']], 409);
            }

            $before = $this->snapshot($target);

            // The move. Platform access goes first so nothing can be retained
            // by an additive merge, then the single business membership is
            // attached.
            $target->syncRoles([]);
            $target->forceFill([
                'owner_user_id'         => $business->owner_id,
                'assigned_country_ids'  => null,
                'assigned_location_ids' => null,
                'assigned_listing_ids'  => null,
                'assigned_program_ids'  => null,
                'custom_permissions'    => null,
            ])->save();
            $target->venues()->detach();

            $membership = BusinessMembership::create([
                'business_id'  => $business->id,
                'user_id'      => $target->id,
                'role'         => $preview->role,
                'scope'        => $preview->scope,
                'location_ids' => $preview->location_ids,
                'status'       => BusinessMembership::STATUS_ACTIVE,
                'version'      => 1,
                'created_by'   => $actor->id,
            ]);

            // Stale tokens would otherwise keep the old platform privileges
            // alive until they expired.
            $target->tokens()->delete();

            $preview->forceFill([
                'consumed_at'     => now(),
                'idempotency_key' => $data['idempotency_key'],
                'membership_id'   => $membership->id,
            ])->save();

            // Before and after, so a move can be investigated later.
            \App\Models\AuditLog::create([
                'action'      => 'business.assignment',
                'resource'    => 'business_membership',
                'resource_id' => (string) $membership->id,
                'actor_id'    => $actor->id,
                'actor_email' => $actor->email,
                'timestamp'   => now(),
                // `details` is a plain string column, not a cast array.
                'details'     => mb_substr((string) json_encode([
                    'business_id' => $business->id,
                    'user_id'     => $target->id,
                    'role'        => $preview->role,
                    'scope'       => $preview->scope,
                    'before'      => $before,
                    'after'       => $this->snapshot($target->fresh()),
                ]), 0, 2000),
            ]);

            return sendSuccess('Account assigned', [
                'contract_version' => self::CONTRACT_VERSION,
                'membership'       => $this->mapMembership($membership),
            ]);
        });
    }

    /** Before/after record for the audit trail. */
    protected function snapshot(User $user): array
    {
        return [
            'roles'                 => $user->getRoleNames()->values()->all(),
            'owner_user_id'         => $user->owner_user_id,
            'assigned_country_ids'  => $user->assigned_country_ids,
            'assigned_location_ids' => $user->assigned_location_ids,
            'custom_permissions'    => $user->custom_permissions,
        ];
    }

    protected function mapMembership(?BusinessMembership $m): ?array
    {
        return $m ? [
            'id'           => (string) $m->id,
            'business_id'  => (string) $m->business_id,
            'user_id'      => (string) $m->user_id,
            'role'         => $m->role,
            'scope'        => $m->scope,
            'location_ids' => $m->location_ids ?? [],
            'status'       => $m->status,
            'version'      => (int) $m->version,
        ] : null;
    }

    /* ─────────────────────────────── shared ──────────────────────────────── */

    /** @return array{0: ?Business, 1: ?User, 2: mixed} */
    /**
     * The actor half of resolve(), on its own.
     *
     * `candidates()` is scoped by the ACTOR, not the business, so the Add
     * business form can offer the same list before a business exists. Sharing
     * this check is the point: a second copy would be a second answer to "who
     * may move accounts", and the two would drift.
     *
     * @return array{0: ?User, 1: ?\Illuminate\Http\JsonResponse}
     */
    protected function resolveActor(Request $request): array
    {
        $actor = $request->user();

        if (! $actor || AccessScope::isProviderScoped($actor)) {
            return [null, sendError('You do not have access to business administration.', [], 403)];
        }
        if (! AccessScope::isGlobal($actor) && ! $actor->hasAnyCapability(['manage_team'])) {
            return [null, sendError('You do not have access to business administration.', [], 403)];
        }

        return [$actor, null];
    }

    /**
     * Accounts assignable to a business that does not exist yet.
     *
     * The Add business form offers an owner at creation time, but creation
     * itself still refuses one (BusinessIdentityService::store) — the account is
     * attached straight afterwards through preview + assign, so the move is
     * audited, reviewable and reversible in exactly one place. This endpoint
     * only fills the picker.
     */
    public function newBusinessCandidates(Request $request)
    {
        [$actor, $error] = $this->resolveActor($request);
        if ($error) {
            return $error;
        }

        return sendSuccess('Assignment candidates', [
            'contract_version' => self::CONTRACT_VERSION,
            'roles'            => array_values(BusinessMembership::ASSIGNABLE_ROLES),
            'candidates'       => $this->candidates($actor),
        ]);
    }

    protected function resolve(Request $request, int $businessId): array
    {
        [$actor, $error] = $this->resolveActor($request);
        if ($error) {
            return [null, null, $error];
        }

        $business = Business::find($businessId);
        if (! $business) {
            return [null, null, sendError('Business not found.', [], 404)];
        }

        // A country admin may only touch businesses in their jurisdiction.
        if (! AccessScope::isGlobal($actor)) {
            $countries = AccessScope::jurisdictionCountryIds($actor);
            if (is_array($countries) && $business->country_id && ! in_array((int) $business->country_id, $countries, true)) {
                return [null, null, sendError('That business is outside your jurisdiction.', [], 403)];
            }
        }

        return [$business, $actor, null];
    }
}
