<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\ClassProgram;
use App\Models\ClassSubscription;
use App\Models\EntryPass;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Door check-in for the admin app: look a pass up by its code, record attendance,
 * and show today's class roster.
 *
 * Scoping is decided here, never by the client: Bookboll staff see every pass,
 * a provider only sees passes and memberships belonging to their own business.
 */
class CheckInService
{
    /** Statuses that still grant entry. */
    private const LIVE = ['active', 'trial', 'trialing', 'paused', 'past_due'];

    public function __construct(protected CustomerProgramService $programs) {}

    public function lookup(Request $request)
    {
        $code = strtoupper(trim((string) $request->query('code', '')));
        if (strlen($code) < 4) {
            return sendError('Enter the code shown on the customer’s pass.');
        }

        $pass = EntryPass::where('code', $code)->first();
        if (! $pass) {
            return sendError('No pass found for this code. Check the characters and try again.');
        }

        $viewer = $this->viewer();

        if ($pass->kind === 'member') {
            return $this->lookupMemberCard($pass, $viewer);
        }

        if ($pass->kind !== 'membership') {
            return sendError('This code is not a membership pass.');
        }

        return $this->lookupMembershipPass($pass, $viewer);
    }

    /** One QR covering every membership: staff pick the class being attended. */
    protected function lookupMemberCard(EntryPass $pass, array $viewer)
    {
        $rows = $this->programs->liveMembershipRows(
            (string) ($pass->ref_id ?: $pass->customer_id),
            strtolower((string) $pass->customer_email)
        );

        $mine = array_values(array_filter($rows, fn ($r) => $this->ownsProgram($r['prog'], $viewer)));
        if (! $mine) {
            return sendError('This member has no memberships at your business.');
        }

        $memberships = array_map(function ($r) {
            $sub = $r['sub'];
            $prog = $r['prog'];
            $joiningFee = max(0, (float) ($sub->joining_fee ?? $prog?->joining_fee ?? 0));

            return array_merge($this->programs->mapSubscriptionPublic($sub, $prog), [
                'programName' => $prog?->name ?: 'Membership',
                'joiningFee'  => $joiningFee,
                'feePaid'     => $joiningFee > 0 && ($sub->first_charge_at || $sub->trial_status !== 'in_trial'),
                'nextBilling' => optional($sub->next_billing_at)?->toDateString() ?: '',
            ]);
        }, $mine);

        $attendance = $this->recentFor($pass, array_map(fn ($r) => $r['sub']->id, $mine));

        return sendSuccess('Pass found', [
            'pass'        => $this->programs->mapPass($pass),
            'kind'        => $pass->kind,
            'customer'    => $this->customerFor($pass),
            'memberships' => $memberships,
            'membership'  => null,
            'attendance'  => $attendance,
            'warnings'    => $this->sharingWarning($attendance),
        ]);
    }

    /** Legacy one-pass-per-membership card. */
    protected function lookupMembershipPass(EntryPass $pass, array $viewer)
    {
        $sub = ClassSubscription::with('program')->where('uuid', $pass->ref_id)->first();
        if (! $sub) {
            return sendError('The membership behind this pass no longer exists.');
        }

        $prog = $sub->program;
        if (! $this->ownsProgram($prog, $viewer)) {
            return sendError('This pass belongs to another business on Bookboll.');
        }

        $joiningFee = max(0, (float) ($sub->joining_fee ?? $prog?->joining_fee ?? 0));
        $warnings = [];

        if (! in_array($sub->status, ['active', 'trial', 'trialing'], true) && $sub->trial_status !== 'in_trial') {
            $warnings[] = "Membership status is \"{$sub->status}\" — not active.";
        }
        if ($sub->trial_status === 'in_trial' && $sub->trial_ends_at && $sub->trial_ends_at->toDateString() < now()->toDateString()) {
            $warnings[] = 'Free trial ended on '.$sub->trial_ends_at->toDateString().' and was never converted to a paid membership.';
        }

        $attendance = $this->recentFor($pass, [$sub->id]);

        return sendSuccess('Pass found', [
            'pass'        => $this->programs->mapPass($pass),
            'kind'        => $pass->kind,
            'customer'    => $this->customerFor($pass, $sub),
            'memberships' => null,
            'membership'  => array_merge($this->programs->mapSubscriptionPublic($sub, $prog), [
                'programName' => $prog?->name ?: 'Membership',
                'joiningFee'  => $joiningFee,
                'feePaid'     => $joiningFee > 0 && ($sub->first_charge_at || $sub->trial_status !== 'in_trial'),
            ]),
            'attendance'  => $attendance,
            'warnings'    => array_merge($warnings, $this->sharingWarning($attendance)),
        ]);
    }

    /** Record a scan. Re-scanning the same pass within 2 minutes is a no-op. */
    public function record(Request $request)
    {
        $data = $request->validate([
            'pass_id'         => 'required|string|max:64',
            'note'            => 'nullable|string|max:500',
            'program_id'      => 'nullable|string|max:64',
            'subscription_id' => 'nullable|string|max:64',
        ]);

        // The API exposes passes by uuid, never the internal id.
        $passId = EntryPass::where('uuid', $data['pass_id'])->value('id');
        if (! $passId) {
            return sendError('Pass not found.');
        }

        $recent = AttendanceLog::where('pass_id', $passId)
            ->where('checked_at', '>', now()->subMinutes(2))
            ->latest('checked_at')
            ->first();

        if ($recent) {
            return sendSuccess('Already recorded', ['log' => $recent, 'duplicate' => true]);
        }

        $log = AttendanceLog::create([
            'pass_id'         => $passId,
            'program_id'      => $this->programIdFrom($data['program_id'] ?? null),
            'subscription_id' => $this->subscriptionIdFrom($data['subscription_id'] ?? null),
            'checked_by'      => auth()->id(),
            'note'            => $data['note'] ?? null,
            'checked_at'      => now(),
        ]);

        return sendSuccess('Attendance recorded', ['log' => $log, 'duplicate' => false]);
    }

    /** The newest check-ins this viewer is allowed to see. */
    public function recent()
    {
        $viewer = $this->viewer();

        $logs = AttendanceLog::with('pass')
            ->when(! $viewer['isPlatform'], function ($q) use ($viewer) {
                $passIds = EntryPass::where('owner_user_id', $viewer['ownerUuid'])->pluck('id');
                $programIds = $this->ownedProgramIds($viewer);
                $q->where(function ($w) use ($passIds, $programIds) {
                    $w->whereIn('pass_id', $passIds)->orWhereIn('program_id', $programIds);
                });
            })
            ->latest('checked_at')
            ->limit(15)
            ->get();

        $names = $this->namesFor($logs->pluck('pass')->filter());

        return sendSuccess('Recent check-ins', [
            'checkins' => $logs->map(fn ($l) => [
                'id'         => $l->id,
                'pass_id'    => $l->pass?->uuid,
                'checked_at' => optional($l->checked_at)?->toIso8601String(),
                'note'       => $l->note,
                'pass'       => $l->pass ? $this->programs->mapPass($l->pass) : null,
                'memberName' => $l->pass ? ($names[strtolower((string) $l->pass->customer_email)] ?? '') : '',
            ])->values(),
        ]);
    }

    /** Today's classes for this provider: who is expected, who has arrived. */
    public function roster()
    {
        $viewer = $this->viewer();
        $programIds = $this->ownedProgramIds($viewer);
        if (! $programIds) {
            return sendSuccess('Roster', ['sessions' => []]);
        }

        $today = now();
        $programs = ClassProgram::whereIn('id', $programIds)
            ->where('status', 'active')
            ->get()
            ->filter(fn ($p) => ($p->category ?: '') !== 'gym'
                && is_array($p->day_of_week_mask)
                && in_array((int) $today->dayOfWeek, array_map('intval', $p->day_of_week_mask), true)
                && $p->session_time)
            ->values();

        if ($programs->isEmpty()) {
            return sendSuccess('Roster', ['sessions' => []]);
        }

        $subs = ClassSubscription::whereIn('program_id', $programs->pluck('id'))
            ->whereIn('status', self::LIVE)
            ->get()
            ->filter(fn ($s) => ! ($s->trial_status === 'in_trial'
                && $s->trial_ends_at
                && $s->trial_ends_at->toDateString() < $today->toDateString()));

        $checkedIn = AttendanceLog::whereDate('checked_at', $today->toDateString())
            ->whereIn('subscription_id', $subs->pluck('id'))
            ->pluck('subscription_id')
            ->all();

        $sessions = $programs->map(function (ClassProgram $p) use ($subs, $checkedIn) {
            $roster = $subs->where('program_id', $p->id)->map(fn ($s) => [
                'id'        => $s->uuid,
                'name'      => $s->customer_name ?: ($s->customer_email ?: 'Member'),
                'status'    => $s->trial_status === 'in_trial' ? 'trial' : $s->status,
                'checkedIn' => in_array($s->id, $checkedIn, true),
            ])->sortBy('name')->values();

            return [
                'programId' => $p->uuid,
                'program'   => ['id' => $p->uuid, 'name' => $p->name, 'color' => $p->color],
                'time'      => $p->session_time,
                'roster'    => $roster,
                'expected'  => $roster->count(),
                'present'   => $roster->where('checkedIn', true)->count(),
            ];
        })->sortBy('time')->values();

        return sendSuccess('Roster', ['sessions' => $sessions]);
    }

    /** Mark a member present from the roster (mints their pass if needed). */
    public function markPresent(Request $request)
    {
        $data = $request->validate(['subscription_id' => 'required|string|max:64']);

        $sub = ClassSubscription::with('program')->where('uuid', $data['subscription_id'])->first();
        if (! $sub) {
            return sendError('Membership not found.');
        }
        if (! $this->ownsProgram($sub->program, $this->viewer())) {
            return sendError('This membership belongs to another business.');
        }

        $prog = $sub->program;
        $owner = $prog?->owner_user_id ?: ($prog?->mapped_owner_user_id ? (string) $prog->mapped_owner_user_id : null);
        $pass = $this->programs->ensurePass('membership', $sub->uuid, $owner, $sub->customer_id, $sub->customer_email);
        if (! $pass) {
            return sendError('Could not create a pass for this member.');
        }

        return $this->record(new Request([
            'pass_id'         => $pass->uuid,
            'note'            => 'Marked present from the class roster',
            'program_id'      => $prog?->uuid,
            'subscription_id' => $sub->uuid,
        ]));
    }

    // ── Scoping ──────────────────────────────────────────────────────────────

    /**
     * Who is scanning. Bookboll staff see everything; a provider is limited to
     * their own business (team members inherit their owner's scope).
     */
    protected function viewer(): array
    {
        $user = auth()->user();
        $isPlatform = $user && $user->hasRole(['super_admin', 'country_admin', 'platform_staff', 'admin']);
        $ownerId = $isPlatform ? null : (int) ($user->owner_user_id ?: $user->id);

        return [
            'isPlatform' => (bool) $isPlatform,
            'ownerId'    => $ownerId,
            // Imported programs still key their owner by the legacy Supabase uuid.
            'ownerUuid'  => $ownerId ? (string) (User::find($ownerId)?->uuid ?: '') : null,
        ];
    }

    protected function ownsProgram(?ClassProgram $prog, array $viewer): bool
    {
        if ($viewer['isPlatform']) {
            return true;
        }
        if (! $prog) {
            return false;
        }

        return (int) $prog->mapped_owner_user_id === $viewer['ownerId']
            || ($viewer['ownerUuid'] && $prog->owner_user_id === $viewer['ownerUuid']);
    }

    /** @return list<int> */
    protected function ownedProgramIds(array $viewer): array
    {
        return ClassProgram::query()
            ->when(! $viewer['isPlatform'], fn ($q) => $q->where(function ($w) use ($viewer) {
                $w->where('mapped_owner_user_id', $viewer['ownerId']);
                if ($viewer['ownerUuid']) {
                    $w->orWhere('owner_user_id', $viewer['ownerUuid']);
                }
            }))
            ->pluck('id')
            ->all();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function customerFor(EntryPass $pass, ?ClassSubscription $sub = null): array
    {
        $user = User::where('uuid', $pass->customer_id ?: $pass->ref_id)->first()
            ?: User::whereRaw('LOWER(email) = ?', [strtolower((string) $pass->customer_email)])->first();

        if ($user) {
            return [
                'id'         => $user->uuid,
                'full_name'  => $user->name ?: '',
                'email'      => $user->email ?: '',
                'phone'      => $user->phone ?: '',
                'avatar_url' => $user->image ?: '',
            ];
        }

        return [
            'id'         => null,
            'full_name'  => $sub?->customer_name ?: '',
            'email'      => $pass->customer_email ?: ($sub?->customer_email ?: ''),
            'phone'      => '',
            'avatar_url' => '',
        ];
    }

    /** @param  list<int>  $subscriptionIds */
    protected function recentFor(EntryPass $pass, array $subscriptionIds): array
    {
        return AttendanceLog::where(function ($q) use ($pass, $subscriptionIds) {
            $q->where('pass_id', $pass->id);
            if ($subscriptionIds) {
                $q->orWhereIn('subscription_id', $subscriptionIds);
            }
        })
            ->latest('checked_at')
            ->limit(10)
            ->get()
            ->map(fn ($l) => [
                'id'         => $l->id,
                'checked_at' => optional($l->checked_at)?->toIso8601String(),
                'note'       => $l->note,
            ])
            ->all();
    }

    /** @param  list<array>  $attendance */
    protected function sharingWarning(array $attendance): array
    {
        if (! $attendance || ! $attendance[0]['checked_at']) {
            return [];
        }

        return now()->diffInMinutes($attendance[0]['checked_at'], true) < 60
            ? ['Already checked in less than an hour ago — possible pass sharing. Verify identity.']
            : [];
    }

    /** @return array<string, string> email => name */
    protected function namesFor($passes): array
    {
        $emails = $passes->pluck('customer_email')->filter()->map(fn ($e) => strtolower($e))->unique()->all();
        if (! $emails) {
            return [];
        }

        return User::whereIn(\DB::raw('LOWER(email)'), $emails)
            ->pluck('name', 'email')
            ->mapWithKeys(fn ($n, $e) => [strtolower($e) => $n])
            ->all();
    }

    protected function programIdFrom(?string $uuid): ?int
    {
        return $uuid ? ClassProgram::where('uuid', $uuid)->value('id') : null;
    }

    protected function subscriptionIdFrom(?string $uuid): ?int
    {
        return $uuid ? ClassSubscription::where('uuid', $uuid)->value('id') : null;
    }
}
