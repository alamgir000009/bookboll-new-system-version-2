<?php

namespace Database\Seeders;

use App\Models\Community;
use App\Support\SupabaseRestClient;
use Database\Seeders\Concerns\ResolvesSupabaseIds;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull communities from Supabase into MySQL.
 *
 *   php artisan db:seed --class=CommunitiesFromSupabaseSeeder
 */
class CommunitiesFromSupabaseSeeder extends Seeder
{
    use ResolvesSupabaseIds;

    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->error($e->getMessage());
            $this->command?->warn('Skipping communities import.');

            return;
        }

        if (! $client->usingServiceRole()) {
            $this->command?->warn(
                'SUPABASE_SERVICE_ROLE_KEY is empty — using anon key. RLS will likely return 0 communities.'
            );
        }

        try {
            // city_id / country_id are unsigned bigints locally but UUIDs in
            // Supabase — resolve them before inserting or every row is truncated.
            $this->loadSupabaseMaps($client);

            $this->command?->info('Fetching communities…');
            $rows = $client->fetchAll('communities', 'created_at.desc', 500, ['created_at.asc', 'id.asc']);
            $ok = 0;
            $skipped = 0;
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $owner = (string) ($row['owner_customer_id'] ?? '');
                if ($id === '' || $owner === '') {
                    $skipped++;
                    continue;
                }
                Community::query()->updateOrCreate(
                    ['uuid' => $id],
                    $this->mapRow($row, $owner)
                );
                $ok++;
            }
            $this->command?->info("communities upserted: {$ok} (skipped {$skipped}, fetched " . count($rows) . ')');
            if ($ok === 0 && ! $client->usingServiceRole()) {
                $this->command?->error(
                    'No communities imported. Set SUPABASE_SERVICE_ROLE_KEY in bookboll-BACKEND/.env and re-run.'
                );
            } elseif ($ok === 0) {
                $this->command?->warn('Supabase returned 0 communities (table empty).');
            }
        } catch (Throwable $e) {
            Log::error('CommunitiesFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->error($e->getMessage());
        }
    }

    protected function mapRow(array $row, string $owner): array
    {
        return [
            'owner_customer_id' => $owner,
            'name'              => (string) ($row['name'] ?? ''),
            'description'       => (string) ($row['description'] ?? ''),
            'sport'             => (string) ($row['sport'] ?? ''),
            'sports'            => $this->jsonVal($row['sports'] ?? null, []),
            'city_id'           => $this->localCityId($row['city_id'] ?? null),
            'country_id'        => $this->localCountryId($row['country_id'] ?? null),
            'cover_image'       => (string) ($row['cover_image'] ?? ''),
            'images'            => $this->jsonVal($row['images'] ?? null, []),
            'is_private'        => (bool) ($row['is_private'] ?? false),
            'settings'          => $this->jsonVal($row['settings'] ?? null, []),
            'moderation_status' => (string) ($row['moderation_status'] ?? 'active'),
            'moderation_reason' => $row['moderation_reason'] ?? null,
            'moderated_at'      => $row['moderated_at'] ?? null,
            'moderated_by'      => $row['moderated_by'] ?? null,
            'avatar_image'      => $row['avatar_image'] ?? null,
            'created_at'        => $row['created_at'] ?? now(),
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
