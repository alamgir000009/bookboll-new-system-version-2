<?php

namespace Database\Seeders;

use App\Models\Community;
use App\Models\CommunityPost;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull community_posts from Supabase into MySQL.
 *
 *   php artisan db:seed --class=CommunityPostsFromSupabaseSeeder
 */
class CommunityPostsFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->error($e->getMessage());
            $this->command?->warn('Skipping community_posts import.');

            return;
        }

        if (! $client->usingServiceRole()) {
            $this->command?->warn(
                'SUPABASE_SERVICE_ROLE_KEY empty — community_posts may return 0 under RLS.'
            );
        }

        try {
            $this->command?->info('Fetching community_posts…');
            $rows = $client->fetchAll('community_posts', 'created_at.desc', 500, ['created_at.asc', 'id.asc']);
            $communityIdsByUuid = Community::query()->pluck('id', 'uuid');
            $ok = 0;
            $skipped = 0;
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $cid = (string) ($row['community_id'] ?? '');
                $author = (string) ($row['author_id'] ?? '');
                $communityPk = $cid !== '' ? $communityIdsByUuid->get($cid) : null;
                if ($id === '' || ! $communityPk || $author === '') {
                    $skipped++;
                    continue;
                }
                CommunityPost::query()->updateOrCreate(
                    ['uuid' => $id],
                    [
                        'community_id'      => (int) $communityPk,
                        'author_id'         => $author,
                        'body'              => (string) ($row['body'] ?? ''),
                        'photos'            => $this->jsonVal($row['photos'] ?? null, []),
                        'moderation_status' => (string) ($row['moderation_status'] ?? 'active'),
                        'moderation_reason' => $row['moderation_reason'] ?? null,
                        'moderated_at'      => $row['moderated_at'] ?? null,
                        'moderated_by'      => $row['moderated_by'] ?? null,
                        'created_at'        => $row['created_at'] ?? now(),
                    ]
                );
                $ok++;
            }
            $this->command?->info("community_posts upserted: {$ok} (skipped {$skipped})");
        } catch (Throwable $e) {
            Log::error('CommunityPostsFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->error($e->getMessage());
        }
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
