<?php

namespace Database\Seeders;

use App\Models\Community;
use App\Models\HostedGame;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull hosted_games from Supabase into MySQL.
 *
 * Requires SUPABASE_SERVICE_ROLE_KEY (RLS may block anon reads).
 *
 *   php artisan db:seed --class=HostedGamesFromSupabaseSeeder
 */
class HostedGamesFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->error($e->getMessage());
            $this->command?->warn('Skipping hosted_games import.');

            return;
        }

        if (! $client->usingServiceRole()) {
            $this->command?->warn(
                'SUPABASE_SERVICE_ROLE_KEY is empty — using anon key. RLS will likely return 0 hosted_games. Set the service role key in .env.'
            );
        }

        try {
            $this->command?->info('Fetching hosted_games…');
            $rows = $client->fetchAll('hosted_games', 'created_at.desc', 500, ['created_at.asc', 'id.asc']);
            $communityIdsByUuid = Community::query()->pluck('id', 'uuid');
            $ok = 0;
            $skipped = 0;
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    $skipped++;
                    continue;
                }
                HostedGame::query()->updateOrCreate(
                    ['uuid' => $id],
                    $this->mapRow($row, $communityIdsByUuid)
                );
                $ok++;
            }
            $this->command?->info("hosted_games upserted: {$ok} (skipped {$skipped}, fetched " . count($rows) . ')');
            if ($ok === 0 && ! $client->usingServiceRole()) {
                $this->command?->error(
                    'No hosted_games imported. Set SUPABASE_SERVICE_ROLE_KEY in bookboll-BACKEND/.env and re-run.'
                );
            } elseif ($ok === 0) {
                $this->command?->warn('Supabase returned 0 hosted_games (table empty).');
            }
        } catch (Throwable $e) {
            Log::error('HostedGamesFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->error($e->getMessage());
        }
    }

    protected function mapRow(array $row, Collection $communityIdsByUuid): array
    {
        $communityUuid = $this->uuidOrNull($row['community_id'] ?? null);
        $communityPk = $communityUuid ? $communityIdsByUuid->get($communityUuid) : null;

        return [
            'host_customer_id'         => $this->uuidOrNull($row['host_customer_id'] ?? null),
            'host_name'                => (string) ($row['host_name'] ?? ''),
            'host_phone'               => (string) ($row['host_phone'] ?? ''),
            'host_whatsapp'            => (string) ($row['host_whatsapp'] ?? ''),
            'location_id'              => $this->uuidOrNull($row['location_id'] ?? null),
            'listing_id'               => $this->uuidOrNull($row['listing_id'] ?? null),
            'booking_id'               => $this->uuidOrNull($row['booking_id'] ?? null),
            'city_id'                  => $this->uuidOrNull($row['city_id'] ?? null),
            'country_id'               => $this->uuidOrNull($row['country_id'] ?? null),
            'title'                    => (string) ($row['title'] ?? ''),
            'sport'                    => (string) ($row['sport'] ?? ''),
            'game_type'                => (string) ($row['game_type'] ?? 'game'),
            'game_date'                => $row['game_date'] ?? null,
            'start_time'               => (string) ($row['start_time'] ?? ''),
            'end_time'                 => (string) ($row['end_time'] ?? ''),
            'price_per_person'         => $row['price_per_person'] ?? 0,
            'currency'                 => (string) ($row['currency'] ?? 'AED'),
            'max_players'              => (int) ($row['max_players'] ?? 10),
            'skill_level'              => (string) ($row['skill_level'] ?? 'all'),
            'description'              => (string) ($row['description'] ?? ''),
            'status'                   => (string) ($row['status'] ?? 'open'),
            'num_teams'                => (int) ($row['num_teams'] ?? 0),
            'format'                   => (string) ($row['format'] ?? 'single_elim'),
            'prize_pot'                => $row['prize_pot'] ?? 0,
            'prize_note'               => (string) ($row['prize_note'] ?? ''),
            'custom_location_name'     => (string) ($row['custom_location_name'] ?? ''),
            'custom_location_address'  => (string) ($row['custom_location_address'] ?? ''),
            'hide_location'            => (bool) ($row['hide_location'] ?? false),
            'images'                   => $this->jsonVal($row['images'] ?? null, []),
            'moderation_status'        => (string) ($row['moderation_status'] ?? 'active'),
            'moderation_reason'        => $row['moderation_reason'] ?? null,
            'moderated_at'             => $row['moderated_at'] ?? null,
            'moderated_by'             => $row['moderated_by'] ?? null,
            'signup_form'              => $this->jsonVal($row['signup_form'] ?? null, []),
            'request_deadline'         => $row['request_deadline'] ?? null,
            'approval_mode'            => (string) ($row['approval_mode'] ?? 'review'),
            'charge'                   => $this->jsonVal($row['charge'] ?? null, null),
            'community_id'             => $communityPk ? (int) $communityPk : null,
            'lock_minutes_before'      => isset($row['lock_minutes_before']) ? (int) $row['lock_minutes_before'] : null,
            'play_format'              => (string) ($row['play_format'] ?? ''),
            'courts_count'             => isset($row['courts_count']) ? (int) $row['courts_count'] : null,
            'rounds'                   => $this->jsonVal($row['rounds'] ?? null, null),
            'community_public'         => (bool) ($row['community_public'] ?? false),
            'allow_waitlist'           => array_key_exists('allow_waitlist', $row) ? (bool) $row['allow_waitlist'] : true,
            'created_at'               => $row['created_at'] ?? now(),
        ];
    }

    protected function uuidOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (string) $v;
    }

    protected function jsonVal(mixed $v, mixed $default)
    {
        if ($v === null) {
            return $default;
        }
        if (is_string($v)) {
            $decoded = json_decode($v, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
        }

        return $v;
    }
}
