<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\Venue;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull pending / pending_content listings from Supabase into MySQL for Approvals testing.
 *
 *   php artisan db:seed --class=ListingsApprovalsFromSupabaseSeeder
 */
class ListingsApprovalsFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->warn($e->getMessage());
            $this->command?->warn('Falling back to ApprovalsDemoSeeder.');
            $this->call(ApprovalsDemoSeeder::class);

            return;
        }

        try {
            $this->command?->info('Fetching locations (for venue name map)…');
            $locations = $client->fetchAll('locations', 'name.asc');
            $locNameById = [];
            foreach ($locations as $loc) {
                if (! empty($loc['id'])) {
                    $locNameById[(string) $loc['id']] = (string) ($loc['name'] ?? '');
                }
            }

            $venuesByName = Venue::query()->get(['id', 'name', 'owner_user_id'])
                ->groupBy(fn (Venue $v) => mb_strtolower(trim((string) $v->name)));

            $this->command?->info('Fetching Supabase listings…');
            $all = $client->fetchAll('listings', 'created_at.asc');
            $queue = [];
            foreach ($all as $row) {
                $pending = ($row['approval_status'] ?? '') === 'pending';
                $pc = $row['pending_content'] ?? null;
                $hasContent = is_array($pc) ? $pc !== [] : (! empty($pc) && $pc !== 'null');
                if ($pending || $hasContent) {
                    $queue[] = $row;
                }
            }

            $this->command?->info('Approvals queue rows from Supabase: ' . count($queue));
            $ok = 0;
            $skip = 0;

            foreach ($queue as $row) {
                $locationId = isset($row['location_id']) ? (string) $row['location_id'] : null;
                $venueId = null;
                if ($locationId && ! empty($locNameById[$locationId])) {
                    $key = mb_strtolower(trim($locNameById[$locationId]));
                    $candidates = $venuesByName->get($key);
                    if ($candidates && $candidates->count() >= 1) {
                        $venueId = (int) $candidates->first()->id;
                    }
                }
                if (! $venueId) {
                    $venueId = Venue::query()->orderBy('id')->value('id');
                }
                if (! $venueId) {
                    $skip++;
                    $this->command?->warn('Skip listing “' . ($row['name'] ?? '?') . '”: no venue');
                    continue;
                }

                $name = (string) ($row['name'] ?? 'Untitled listing');
                $existing = Listing::query()
                    ->where('venue_id', $venueId)
                    ->where('name', $name)
                    ->first();

                $attrs = $this->mapListing($row, (int) $venueId);
                if ($existing) {
                    $existing->update($attrs);
                } else {
                    Listing::query()->create($attrs);
                }
                $ok++;
            }

            $this->command?->info("Listings approvals upserted: {$ok}" . ($skip ? " (skipped {$skip})" : ''));
        } catch (Throwable $e) {
            Log::error('ListingsApprovalsFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->error($e->getMessage());
            $this->command?->warn('Falling back to ApprovalsDemoSeeder.');
            $this->call(ApprovalsDemoSeeder::class);
        }
    }

    protected function mapListing(array $row, int $venueId): array
    {
        $photos = $row['photos'] ?? null;
        if (is_string($photos)) {
            $decoded = json_decode($photos, true);
            $photos = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($photos)) {
            $photos = [];
        }

        $pending = $row['pending_content'] ?? null;
        if (is_string($pending)) {
            $decoded = json_decode($pending, true);
            $pending = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($pending) || $pending === []) {
            $pending = null;
        }

        $snapshot = $row['previous_snapshot'] ?? null;
        if (is_string($snapshot)) {
            $decoded = json_decode($snapshot, true);
            $snapshot = is_array($decoded) ? $decoded : null;
        }

        $slot = $row['slot_duration'] ?? 60;
        // Supabase often stores hours as float; Laravel slot_duration is minutes-ish int in dashboard.
        if (is_numeric($slot) && (float) $slot > 0 && (float) $slot <= 24) {
            $slotMinutes = (int) round((float) $slot * 60);
            if ($slotMinutes < 15) {
                $slotMinutes = (int) $slot;
            }
        } else {
            $slotMinutes = (int) ($slot ?: 60);
        }

        return [
            'name'                  => (string) ($row['name'] ?? 'Untitled listing'),
            'venue_id'              => $venueId,
            'description'           => $row['description'] ?? '',
            'base_price'            => (float) ($row['base_price'] ?? 0),
            'capacity'              => (int) ($row['capacity'] ?? 1),
            'slot_duration'         => $slotMinutes,
            'buffer_time'           => (int) ($row['buffer_time'] ?? 0),
            'max_bookings_per_slot' => (int) ($row['max_bookings_per_slot'] ?? 1),
            'loyalty_points'        => (int) ($row['loyalty_points'] ?? 0),
            'status'                => (($row['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active',
            'sport_category'        => $row['sport_category'] ?? '',
            'category'              => $row['category'] ?? 'sports',
            'listing_type'          => $row['listing_type'] ?? 'per_space',
            'photo_url'             => $row['photo_url'] ?? ($photos[0] ?? null),
            'photos'                => $photos,
            'payment_options'       => is_array($row['payment_options'] ?? null) ? $row['payment_options'] : ['pay_at_venue'],
            'approval_status'       => $row['approval_status'] ?? 'pending',
            'eligibility_status'    => $row['eligibility_status'] ?? 'eligible',
            'admin_note'            => $row['admin_note'] ?? null,
            'previous_snapshot'     => is_array($snapshot) ? $snapshot : null,
            'pending_content'       => $pending,
            'show_on_marketplace'   => array_key_exists('show_on_marketplace', $row) ? (bool) $row['show_on_marketplace'] : true,
            'show_on_provider_site' => array_key_exists('show_on_provider_site', $row) ? (bool) $row['show_on_provider_site'] : true,
            'extension_pricing'     => $row['extension_pricing'] ?? 'none',
            'extension_value'       => (float) ($row['extension_value'] ?? 0),
            'submitted_at'          => $row['submitted_at'] ?? now(),
            'submitted_by_name'     => $row['submitted_by_name'] ?? null,
            'calendar_color'        => $row['booking_color'] ?? $row['calendar_color'] ?? '#10b981',
        ];
    }
}
