<?php

namespace Database\Seeders;

use App\Models\ClassProgram;
use App\Models\ClassSession;
use App\Models\ClassSubscription;
use App\Models\User;
use App\Models\Venue;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull class_programs / class_subscriptions / class_sessions from Supabase
 * and upsert into MySQL (idempotent by UUID id).
 *
 *   php artisan db:seed --class=ClassMembershipFromSupabaseSeeder
 */
class ClassMembershipFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->warn($e->getMessage());
            $this->command?->warn('Falling back to ClassMembershipDemoSeeder.');
            $this->call(ClassMembershipDemoSeeder::class);

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

            $laravelUserIds = User::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $laravelUserIdSet = array_fill_keys($laravelUserIds, true);

            $this->command?->info('Fetching class_programs…');
            $programs = $client->fetchAll('class_programs', 'created_at.asc');
            $programOk = 0;
            $programWarn = 0;

            foreach ($programs as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $locationId = isset($row['location_id']) ? (string) $row['location_id'] : null;
                $venueId = null;
                if ($locationId && ! empty($locNameById[$locationId])) {
                    $key = mb_strtolower(trim($locNameById[$locationId]));
                    $candidates = $venuesByName->get($key);
                    if ($candidates && $candidates->count() === 1) {
                        $venueId = (int) $candidates->first()->id;
                    } elseif ($candidates && $candidates->count() > 1) {
                        $programWarn++;
                        $this->command?->warn("Ambiguous venue match for location “{$locNameById[$locationId]}” (program {$id})");
                    }
                }

                $ownerUuid = isset($row['owner_user_id']) ? (string) $row['owner_user_id'] : null;
                $mappedOwner = null;
                // Only map when Supabase stored a numeric id that already exists in Laravel users
                // (unlikely for uuid owners). Leave mapped_owner_user_id null for uuid owners.
                if ($ownerUuid && ctype_digit($ownerUuid) && isset($laravelUserIdSet[(int) $ownerUuid])) {
                    $mappedOwner = (int) $ownerUuid;
                }

                ClassProgram::query()->updateOrCreate(
                    ['id' => $id],
                    $this->mapProgram($row, $venueId, $mappedOwner)
                );
                $programOk++;
            }

            $this->command?->info("Programs upserted: {$programOk}" . ($programWarn ? " ({$programWarn} venue warnings)" : ''));

            $this->command?->info('Fetching class_subscriptions…');
            $subs = $client->fetchAll('class_subscriptions', 'created_at.asc');
            $knownPrograms = ClassProgram::query()->pluck('id')->flip();
            $subOk = 0;
            $subSkip = 0;

            foreach ($subs as $row) {
                $id = (string) ($row['id'] ?? '');
                $programId = (string) ($row['program_id'] ?? '');
                if ($id === '' || $programId === '') {
                    $subSkip++;
                    continue;
                }
                if (! $knownPrograms->has($programId)) {
                    $subSkip++;
                    $this->command?->warn("Skip subscription {$id}: missing program {$programId}");
                    continue;
                }

                ClassSubscription::query()->updateOrCreate(
                    ['id' => $id],
                    $this->mapSubscription($row)
                );
                $subOk++;
            }

            $this->command?->info("Subscriptions upserted: {$subOk}" . ($subSkip ? " (skipped {$subSkip})" : ''));

            $this->command?->info('Fetching class_sessions…');
            $sessions = $client->fetchAll('class_sessions', 'session_date.asc');
            $knownSubs = ClassSubscription::query()->pluck('id')->flip();
            $sessOk = 0;
            $sessSkip = 0;

            foreach ($sessions as $row) {
                $id = (string) ($row['id'] ?? '');
                $subId = (string) ($row['subscription_id'] ?? '');
                $programId = (string) ($row['program_id'] ?? '');
                if ($id === '' || $subId === '' || $programId === '') {
                    $sessSkip++;
                    continue;
                }
                if (! $knownSubs->has($subId) || ! $knownPrograms->has($programId)) {
                    $sessSkip++;
                    continue;
                }

                ClassSession::query()->updateOrCreate(
                    ['id' => $id],
                    $this->mapSession($row)
                );
                $sessOk++;
            }

            $this->command?->info("Sessions upserted: {$sessOk}" . ($sessSkip ? " (skipped {$sessSkip})" : ''));
            $this->command?->info('Class membership import complete.');
        } catch (Throwable $e) {
            Log::error('ClassMembershipFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->error($e->getMessage());
            $this->command?->warn('Falling back to ClassMembershipDemoSeeder.');
            $this->call(ClassMembershipDemoSeeder::class);
        }
    }

    protected function mapProgram(array $row, ?int $venueId, ?int $mappedOwner): array
    {
        return [
            'provider_id'               => $this->uuidOrNull($row['provider_id'] ?? null),
            'listing_id'                => $this->uuidOrNull($row['listing_id'] ?? null),
            'listing_ids'               => $this->jsonArray($row['listing_ids'] ?? null),
            'location_id'               => $this->uuidOrNull($row['location_id'] ?? null),
            'venue_id'                  => $venueId,
            'owner_user_id'             => $this->uuidOrNull($row['owner_user_id'] ?? null),
            'mapped_owner_user_id'      => $mappedOwner,
            'name'                      => (string) ($row['name'] ?? 'Untitled program'),
            'description'               => $row['description'] ?? '',
            'sport_category'            => $row['sport_category'] ?? '',
            'coach_name'                => $row['coach_name'] ?? '',
            'monthly_price'             => $row['monthly_price'] ?? 0,
            'currency'                  => $row['currency'] ?? 'AED',
            'sessions_per_week'         => (int) ($row['sessions_per_week'] ?? 1),
            'session_duration_minutes'  => (int) ($row['session_duration_minutes'] ?? 60),
            'day_of_week_mask'          => $this->jsonArray($row['day_of_week_mask'] ?? [1]),
            'session_time'              => $this->hm($row['session_time'] ?? '18:00'),
            'capacity'                  => (int) ($row['capacity'] ?? 12),
            'commission_percentage'     => $row['commission_percentage'] ?? 20,
            'status'                    => $row['status'] ?? 'active',
            'photo_url'                 => $row['photo_url'] ?? '',
            'images'                    => $this->jsonArray($row['images'] ?? []),
            'color'                     => $row['color'] ?? '#7c3aed',
            'sort_order'                => (int) ($row['sort_order'] ?? 0),
            'billing_interval'          => $row['billing_interval'] ?? 'monthly',
            'program_type'              => $row['program_type'] ?? 'ongoing',
            'duration_weeks'            => $row['duration_weeks'] ?? null,
            'start_date'                => $this->dateOrNull($row['start_date'] ?? null),
            'end_date'                  => $this->dateOrNull($row['end_date'] ?? null),
            'bonus_months'              => (int) ($row['bonus_months'] ?? 0),
            'free_trial_days'           => (int) ($row['free_trial_days'] ?? 14),
            'free_trial_enabled'        => (bool) ($row['free_trial_enabled'] ?? true),
            'free_trial_sessions'       => (int) ($row['free_trial_sessions'] ?? 0),
            'discount_months'           => (int) ($row['discount_months'] ?? 0),
            'joining_fee'               => $row['joining_fee'] ?? 0,
            'is_draft'                  => (bool) ($row['is_draft'] ?? false),
            'show_on_marketplace'       => array_key_exists('show_on_marketplace', $row) ? (bool) $row['show_on_marketplace'] : true,
            'show_on_provider_site'     => array_key_exists('show_on_provider_site', $row) ? (bool) $row['show_on_provider_site'] : true,
            'category'                  => $row['category'] ?? 'academy',
            'audience'                  => $row['audience'] ?? null,
            'gender_policy'             => $row['gender_policy'] ?? 'mixed',
            'signup_form'               => $this->jsonArray($row['signup_form'] ?? []),
            'approval_status'           => $row['approval_status'] ?? 'approved',
            'eligibility_status'        => $row['eligibility_status'] ?? 'eligible',
            'admin_note'                => $row['admin_note'] ?? '',
            'previous_snapshot'         => is_array($row['previous_snapshot'] ?? null) ? $row['previous_snapshot'] : null,
            'pending_content'           => is_array($row['pending_content'] ?? null) ? $row['pending_content'] : null,
            'trial_terms_accepted_at'   => $this->tsOrNull($row['trial_terms_accepted_at'] ?? null),
            'trial_terms_version'       => $row['trial_terms_version'] ?? '',
            'submitted_at'              => $this->tsOrNull($row['submitted_at'] ?? null),
            'submitted_by_name'         => $row['submitted_by_name'] ?? null,
            'created_at'                => $this->tsOrNull($row['created_at'] ?? null) ?: now(),
            'updated_at'                => $this->tsOrNull($row['updated_at'] ?? null) ?: now(),
        ];
    }

    protected function mapSubscription(array $row): array
    {
        return [
            'program_id'                => (string) $row['program_id'],
            'customer_id'               => $this->uuidOrNull($row['customer_id'] ?? null),
            'customer_name'             => $row['customer_name'] ?? '',
            'customer_email'            => $row['customer_email'] ?? '',
            'started_at'                => $this->dateOrNull($row['started_at'] ?? null),
            'next_billing_at'           => $this->dateOrNull($row['next_billing_at'] ?? null),
            'trial_ends_at'             => $this->dateOrNull($row['trial_ends_at'] ?? null),
            'status'                    => $row['status'] ?? 'active',
            'trial_status'              => $row['trial_status'] ?? 'in_trial',
            'billing_interval'          => $row['billing_interval'] ?? null,
            'monthly_amount'            => $row['monthly_amount'] ?? 0,
            'provider_payout'           => $row['provider_payout'] ?? 0,
            'platform_fee'              => $row['platform_fee'] ?? 0,
            'card_on_file_id'           => $row['card_on_file_id'] ?? '',
            'first_charge_at'           => $this->tsOrNull($row['first_charge_at'] ?? null),
            'cancelled_during_trial_at' => $this->tsOrNull($row['cancelled_during_trial_at'] ?? null),
            'cancelled_at'              => $this->tsOrNull($row['cancelled_at'] ?? null),
            'paused_until'              => $this->tsOrNull($row['paused_until'] ?? null),
            'payment_failed_at'         => $this->tsOrNull($row['payment_failed_at'] ?? null),
            'country_id'                => $this->uuidOrNull($row['country_id'] ?? null),
            'wallet_id'                 => $this->uuidOrNull($row['wallet_id'] ?? null),
            'form_answers'              => is_array($row['form_answers'] ?? null) ? $row['form_answers'] : [],
            'is_comp'                   => (bool) ($row['is_comp'] ?? false),
            'comp_reason'               => $row['comp_reason'] ?? null,
            'cancel_reason'             => $row['cancel_reason'] ?? null,
            'created_at'                => $this->tsOrNull($row['created_at'] ?? null) ?: now(),
            'updated_at'                => $this->tsOrNull($row['updated_at'] ?? null) ?: now(),
        ];
    }

    protected function mapSession(array $row): array
    {
        return [
            'subscription_id'  => (string) $row['subscription_id'],
            'program_id'       => (string) $row['program_id'],
            'listing_id'       => $this->uuidOrNull($row['listing_id'] ?? null),
            'session_date'     => $this->dateOrNull($row['session_date'] ?? null) ?: now()->toDateString(),
            'session_time'     => $this->hm($row['session_time'] ?? '18:00'),
            'duration_minutes' => (int) ($row['duration_minutes'] ?? 60),
            'status'           => $row['status'] ?? 'scheduled',
            'created_at'       => $this->tsOrNull($row['created_at'] ?? null) ?: now(),
            'updated_at'       => $this->tsOrNull($row['updated_at'] ?? null) ?: now(),
        ];
    }

    protected function uuidOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (string) $v;
    }

    protected function jsonArray(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $decoded = json_decode($v, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    protected function hm(mixed $v): string
    {
        $s = (string) ($v ?? '18:00');
        if (strlen($s) >= 5) {
            return substr($s, 0, 5);
        }

        return $s ?: '18:00';
    }

    protected function dateOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string) $v;

        return strlen($s) >= 10 ? substr($s, 0, 10) : $s;
    }

    protected function tsOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (string) $v;
    }
}
