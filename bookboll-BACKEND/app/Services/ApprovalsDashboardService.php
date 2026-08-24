<?php

namespace App\Services;

use App\Models\ClassProgram;
use App\Models\CustomType;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Log;

/**
 * Admin Approvals queue — listings + class_programs (content tab).
 */
class ApprovalsDashboardService
{
    public function bootstrap($request)
    {
        $listings = $this->pendingListings();
        $programs = $this->pendingPrograms();

        $items = [];
        foreach ($listings as $row) {
            $items[] = array_merge($row, ['_type' => 'listing']);
        }
        foreach ($programs as $row) {
            $items[] = array_merge($row, ['_type' => 'program']);
        }

        return sendSuccess('Approvals dashboard fetched', [
            'listings'  => $listings,
            'programs'  => $programs,
            'items'     => $items,
            'providers' => $this->buildProviders($listings, $programs),
        ]);
    }

    public function approve($request, string $type, string $id)
    {
        if (! in_array($type, ['listing', 'program'], true)) {
            return sendError('Invalid type.', [], 422);
        }

        return $type === 'listing'
            ? $this->approveListing($id)
            : $this->approveProgram($id);
    }

    public function requestChanges($request, string $type, string $id)
    {
        if (! in_array($type, ['listing', 'program'], true)) {
            return sendError('Invalid type.', [], 422);
        }

        $data = $request->validate([
            'admin_note' => 'nullable|string|max:2000',
        ]);
        $note = trim((string) ($data['admin_note'] ?? ''));

        return $type === 'listing'
            ? $this->requestListingChanges($id, $note)
            : $this->requestProgramChanges($id, $note);
    }

    public function bulkApprove($request)
    {
        $data = $request->validate([
            'items'        => 'required|array|min:1',
            'items.*.type' => 'required|in:listing,program',
            'items.*.id'   => 'required|string',
        ]);

        $ok = 0;
        $errors = [];
        foreach ($data['items'] as $item) {
            try {
                if ($item['type'] === 'listing') {
                    $li = Listing::query()->with(['venue', 'sportsCategory'])->find($item['id']);
                    if (! $li) {
                        $errors[] = "listing:{$item['id']} — not found";
                        continue;
                    }
                    $this->applyListingApprove($li);
                } else {
                    $pg = ClassProgram::query()->find($item['id']);
                    if (! $pg) {
                        $errors[] = "program:{$item['id']} — not found";
                        continue;
                    }
                    $this->applyProgramApprove($pg);
                }
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = "{$item['type']}:{$item['id']} — " . $e->getMessage();
            }
        }

        return sendSuccess("Bulk-approved {$ok} item(s)", [
            'count'  => $ok,
            'errors' => $errors,
        ]);
    }

    // ── Listing ──────────────────────────────────────────────────────────────

    protected function approveListing(string $id, bool $wrap = true)
    {
        $li = Listing::query()->with(['venue', 'sportsCategory'])->find($id);
        if (! $li) {
            return sendError('Listing not found', [], 404);
        }

        try {
            $this->applyListingApprove($li);
        } catch (\Throwable $e) {
            Log::error('approve listing failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Could not approve listing.');
        }

        $mapped = $this->mapListing($li->fresh(['venue', 'sportsCategory']));

        return sendSuccess('Listing approved', ['item' => array_merge($mapped, ['_type' => 'listing'])]);
    }

    protected function applyListingApprove(Listing $li): void
    {
        if ($this->isContentUpdate($li->approval_status, $li->pending_content)) {
            $pc = is_array($li->pending_content) ? $li->pending_content : [];
            $li->update([
                'name'            => $pc['name'] ?? $li->name,
                'description'     => $pc['description'] ?? $li->description,
                'photo_url'       => $pc['photo_url'] ?? $li->photo_url,
                'photos'          => isset($pc['photos']) && is_array($pc['photos']) ? $pc['photos'] : ($li->photos ?: []),
                'pending_content' => null,
            ]);

            return;
        }

        $li->update([
            'approval_status'    => Listing::APPROVAL_APPROVED,
            'eligibility_status' => Listing::ELIGIBILITY_ELIGIBLE,
            'admin_note'         => '',
            'previous_snapshot'  => null,
        ]);
        $this->promoteType(CustomType::DOMAIN_VENUE, $li->sport_category ?: ($li->sportsCategory?->name ?? ''), $li->category);
    }

    protected function requestListingChanges(string $id, string $note)
    {
        $li = Listing::query()->find($id);
        if (! $li) {
            return sendError('Listing not found', [], 404);
        }

        if ($this->isContentUpdate($li->approval_status, $li->pending_content)) {
            $li->update(['pending_content' => null]);
        } else {
            if ($note === '') {
                return sendError('Please enter a note for the provider.', [], 422);
            }
            $li->update([
                'eligibility_status' => Listing::ELIGIBILITY_PAUSED,
                'admin_note'         => $note,
                'approval_status'    => Listing::APPROVAL_APPROVED,
                'previous_snapshot'  => null,
            ]);
        }

        return sendSuccess('Changes requested', [
            'item' => array_merge($this->mapListing($li->fresh(['venue', 'sportsCategory'])), ['_type' => 'listing']),
        ]);
    }

    // ── Program ──────────────────────────────────────────────────────────────

    protected function approveProgram(string $id, bool $wrap = true)
    {
        $pg = ClassProgram::query()->find($id);
        if (! $pg) {
            return sendError('Program not found', [], 404);
        }

        try {
            $this->applyProgramApprove($pg);
        } catch (\Throwable $e) {
            Log::error('approve program failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Could not approve program.');
        }

        $mapped = $this->mapProgram($pg->fresh());

        return sendSuccess('Program approved', ['item' => array_merge($mapped, ['_type' => 'program'])]);
    }

    protected function applyProgramApprove(ClassProgram $pg): void
    {
        if ($this->isContentUpdate($pg->approval_status, $pg->pending_content)) {
            $pc = is_array($pg->pending_content) ? $pg->pending_content : [];
            $pg->update([
                'name'            => $pc['name'] ?? $pg->name,
                'description'     => $pc['description'] ?? $pg->description,
                'photo_url'       => $pc['photo_url'] ?? $pg->photo_url,
                'images'          => isset($pc['images']) && is_array($pc['images']) ? $pc['images'] : ($pg->images ?: []),
                'pending_content' => null,
            ]);

            return;
        }

        $pg->update([
            'approval_status'    => 'approved',
            'eligibility_status' => 'eligible',
            'admin_note'         => '',
            'previous_snapshot'  => null,
        ]);
        $this->promoteType(CustomType::DOMAIN_PROGRAM, $pg->sport_category ?? '', $pg->category);
    }

    protected function requestProgramChanges(string $id, string $note)
    {
        $pg = ClassProgram::query()->find($id);
        if (! $pg) {
            return sendError('Program not found', [], 404);
        }

        if ($this->isContentUpdate($pg->approval_status, $pg->pending_content)) {
            $pg->update(['pending_content' => null]);
        } else {
            if ($note === '') {
                return sendError('Please enter a note for the provider.', [], 422);
            }
            $pg->update([
                'eligibility_status' => 'paused',
                'admin_note'         => $note,
                'approval_status'    => 'approved',
                'previous_snapshot'  => null,
            ]);
        }

        return sendSuccess('Changes requested', [
            'item' => array_merge($this->mapProgram($pg->fresh()), ['_type' => 'program']),
        ]);
    }

    // ── Queries / maps ───────────────────────────────────────────────────────

    protected function pendingListings(): array
    {
        $pending = Listing::query()
            ->with(['venue', 'sportsCategory'])
            ->where('approval_status', Listing::APPROVAL_PENDING)
            ->get();

        $content = Listing::query()
            ->with(['venue', 'sportsCategory'])
            ->whereNotNull('pending_content')
            ->get();

        $byId = [];
        foreach ($pending->concat($content) as $li) {
            if ($li->pending_content === null && $li->approval_status !== Listing::APPROVAL_PENDING) {
                continue;
            }
            // pending_content may be empty array — treat as absent
            if ($li->approval_status !== Listing::APPROVAL_PENDING) {
                $pc = $li->pending_content;
                if (! is_array($pc) || $pc === []) {
                    continue;
                }
            }
            $byId[$li->id] = $this->mapListing($li);
        }

        return array_values($byId);
    }

    protected function pendingPrograms(): array
    {
        $pending = ClassProgram::query()
            ->where('approval_status', 'pending')
            ->get();

        $content = ClassProgram::query()
            ->whereNotNull('pending_content')
            ->get();

        $byId = [];
        foreach ($pending->concat($content) as $pg) {
            if ($pg->approval_status !== 'pending') {
                $pc = $pg->pending_content;
                if (! is_array($pc) || $pc === []) {
                    continue;
                }
            }
            $byId[$pg->id] = $this->mapProgram($pg);
        }

        return array_values($byId);
    }

    protected function mapListing(Listing $l): array
    {
        $photos = is_array($l->photos) ? $l->photos : [];
        if (! $photos && $l->photo_url) {
            $photos = [$l->photo_url];
        }
        $venue = $l->venue;
        $ownerId = $venue?->owner_user_id ? (string) $venue->owner_user_id : null;

        return [
            'id'                    => (string) $l->id,
            'location_id'           => $l->venue_id ? (string) $l->venue_id : null,
            'venue_id'              => $l->venue_id ? (string) $l->venue_id : null,
            'owner_user_id'         => $ownerId,
            'name'                  => $l->name,
            'description'           => $l->description ?? '',
            'sport_category'        => $l->sport_category ?: ($l->sportsCategory?->name ?? ''),
            'category'              => $l->category ?? 'sports',
            'base_price'            => (float) ($l->base_price ?? 0),
            'slot_duration'         => (int) ($l->slot_duration ?? 60),
            'buffer_time'           => (int) ($l->buffer_time ?? 0),
            'capacity'              => (int) ($l->capacity ?? 0),
            'status'                => $l->status ?? 'active',
            'loyalty_points'        => (int) ($l->loyalty_points ?? 0),
            'listing_type'          => $l->listing_type ?? 'per_space',
            'max_bookings_per_slot' => (int) ($l->max_bookings_per_slot ?? 1),
            'photo_url'             => $l->photo_url ?? ($photos[0] ?? ''),
            'photos'                => $photos,
            'approval_status'       => $l->approval_status ?? Listing::APPROVAL_APPROVED,
            'eligibility_status'    => $l->eligibility_status ?? Listing::ELIGIBILITY_ELIGIBLE,
            'admin_note'            => $l->admin_note ?? '',
            'previous_snapshot'     => $l->previous_snapshot,
            'pending_content'       => $l->pending_content,
            'show_on_marketplace'   => $l->show_on_marketplace !== false,
            'show_on_provider_site' => $l->show_on_provider_site !== false,
            'extension_pricing'     => $l->extension_pricing ?? 'none',
            'extension_value'       => (float) ($l->extension_value ?? 0),
            'submitted_at'          => optional($l->submitted_at)?->toIso8601String(),
            'submitted_by_name'     => $l->submitted_by_name,
            'created_at'            => optional($l->created_at)?->toIso8601String(),
        ];
    }

    protected function mapProgram(ClassProgram $p): array
    {
        $ownerId = $p->mapped_owner_user_id
            ? (string) $p->mapped_owner_user_id
            : ($p->owner_user_id ? (string) $p->owner_user_id : null);

        return [
            'id'                       => $p->id,
            'name'                     => $p->name,
            'description'              => $p->description,
            'sport_category'           => $p->sport_category,
            'coach_name'               => $p->coach_name,
            'monthly_price'            => (float) $p->monthly_price,
            'currency'                 => $p->currency,
            'sessions_per_week'        => $p->sessions_per_week,
            'session_duration_minutes' => $p->session_duration_minutes,
            'day_of_week_mask'         => $p->day_of_week_mask ?? [],
            'session_time'             => $p->session_time,
            'capacity'                 => $p->capacity,
            'commission_percentage'    => (float) $p->commission_percentage,
            'status'                   => $p->status,
            'photo_url'                => $p->photo_url,
            'images'                   => $p->images ?? [],
            'location_id'              => $p->location_id,
            'venue_id'                 => $p->venue_id,
            'owner_user_id'            => $ownerId,
            'owner_user_id_uuid'       => $p->owner_user_id,
            'mapped_owner_user_id'     => $p->mapped_owner_user_id,
            'billing_interval'         => $p->billing_interval,
            'program_type'             => $p->program_type,
            'start_date'               => optional($p->start_date)?->toDateString(),
            'end_date'                 => optional($p->end_date)?->toDateString(),
            'free_trial_days'          => $p->free_trial_days,
            'free_trial_sessions'      => $p->free_trial_sessions,
            'discount_months'          => $p->discount_months,
            'joining_fee'              => (float) ($p->joining_fee ?? 0),
            'category'                 => $p->category,
            'audience'                 => $p->audience,
            'gender_policy'            => $p->gender_policy,
            'show_on_marketplace'      => (bool) $p->show_on_marketplace,
            'show_on_provider_site'    => (bool) $p->show_on_provider_site,
            'approval_status'          => $p->approval_status,
            'eligibility_status'       => $p->eligibility_status,
            'admin_note'               => $p->admin_note,
            'previous_snapshot'        => $p->previous_snapshot,
            'pending_content'          => $p->pending_content,
            'submitted_at'             => optional($p->submitted_at)?->toIso8601String(),
            'submitted_by_name'        => $p->submitted_by_name,
            'created_at'               => optional($p->created_at)?->toIso8601String(),
        ];
    }

    protected function isContentUpdate(?string $approvalStatus, $pendingContent): bool
    {
        if (($approvalStatus ?? '') !== 'approved') {
            return false;
        }
        if (! is_array($pendingContent) || $pendingContent === []) {
            return false;
        }

        return true;
    }

    protected function promoteType(string $domain, ?string $name, ?string $category): void
    {
        $clean = trim((string) $name);
        $cat = trim((string) $category);
        if ($clean === '' || $cat === '') {
            return;
        }

        CustomType::query()
            ->where('domain', $domain)
            ->where('category', $cat)
            ->where('status', CustomType::STATUS_PENDING)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($clean)])
            ->update([
                'status'      => CustomType::STATUS_APPROVED,
                'approved_at' => now(),
            ]);
    }

    protected function buildProviders(array $listings, array $programs): array
    {
        $ids = [];
        foreach ($listings as $l) {
            if (! empty($l['owner_user_id'])) {
                $ids[(string) $l['owner_user_id']] = true;
            }
        }
        foreach ($programs as $p) {
            if (! empty($p['owner_user_id'])) {
                $ids[(string) $p['owner_user_id']] = true;
            }
        }

        $out = [];
        foreach (array_keys($ids) as $id) {
            if (ctype_digit((string) $id)) {
                $u = User::query()->find((int) $id);
                $out[] = [
                    'id'              => (string) $id,
                    'full_name'       => $u?->name,
                    'business_name'   => $u?->name,
                    'role'            => 'provider_admin',
                    'business_status' => 'approved',
                ];
            } else {
                $out[] = [
                    'id'              => (string) $id,
                    'full_name'       => 'Provider',
                    'business_name'   => 'Provider',
                    'role'            => 'provider_admin',
                    'business_status' => 'approved',
                ];
            }
        }

        return $out;
    }
}
