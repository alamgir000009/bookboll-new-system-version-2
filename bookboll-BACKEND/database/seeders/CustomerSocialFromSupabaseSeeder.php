<?php

namespace Database\Seeders;

use App\Models\CustomerFollow;
use App\Models\CustomerPost;
use App\Models\StreetCred;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Import customer_follows, customer_posts, street_cred from Supabase.
 *
 *   php artisan db:seed --class=CustomerSocialFromSupabaseSeeder
 */
class CustomerSocialFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->error($e->getMessage());
            $this->command?->warn('Skipping social import.');

            return;
        }

        if (! $client->usingServiceRole()) {
            $this->command?->warn(
                'SUPABASE_SERVICE_ROLE_KEY is empty — social tables may import 0 rows.'
            );
        }

        $this->importFollows($client);
        $this->importPosts($client);
        $this->importStreetCred($client);
    }

    protected function importFollows(SupabaseRestClient $client): void
    {
        try {
            $this->command?->info('Fetching customer_follows…');
            $rows = $client->fetchAll('customer_follows', 'created_at.asc', 500, ['id.asc']);
            $ok = 0;
            foreach ($rows as $row) {
                $follower = (string) ($row['follower_id'] ?? '');
                $following = (string) ($row['following_id'] ?? '');
                if ($follower === '' || $following === '') {
                    continue;
                }
                $status = (string) ($row['status'] ?? 'pending');
                if (! in_array($status, ['pending', 'accepted'], true)) {
                    $status = $status === 'accepted' ? 'accepted' : 'pending';
                }
                CustomerFollow::query()->updateOrCreate(
                    ['follower_uuid' => $follower, 'following_uuid' => $following],
                    ['status' => $status]
                );
                $ok++;
            }
            $this->command?->info("customer_follows upserted: {$ok}");
        } catch (Throwable $e) {
            Log::error('CustomerSocialFromSupabaseSeeder follows: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->warn('customer_follows import failed: ' . $e->getMessage());
        }
    }

    protected function importPosts(SupabaseRestClient $client): void
    {
        try {
            $this->command?->info('Fetching customer_posts…');
            $rows = $client->fetchAll('customer_posts', 'created_at.desc', 500, ['created_at.asc', 'id.asc']);
            $ok = 0;
            foreach ($rows as $row) {
                $author = (string) ($row['author_id'] ?? '');
                if ($author === '') {
                    continue;
                }
                $uuid = (string) ($row['id'] ?? '');
                if ($uuid === '') {
                    $uuid = (string) Str::uuid();
                }
                $photos = $row['photos'] ?? [];
                if (! is_array($photos)) {
                    $photos = [];
                }
                CustomerPost::query()->updateOrCreate(
                    ['uuid' => $uuid],
                    [
                        'author_uuid' => $author,
                        'body'        => (string) ($row['body'] ?? ''),
                        'photos'      => array_values(array_filter($photos)),
                        'created_at'  => $row['created_at'] ?? now(),
                        'updated_at'  => $row['updated_at'] ?? now(),
                    ]
                );
                $ok++;
            }
            $this->command?->info("customer_posts upserted: {$ok}");
        } catch (Throwable $e) {
            Log::error('CustomerSocialFromSupabaseSeeder posts: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->warn('customer_posts import failed: ' . $e->getMessage());
        }
    }

    protected function importStreetCred(SupabaseRestClient $client): void
    {
        try {
            $this->command?->info('Fetching street_cred…');
            $rows = $client->fetchAll('street_cred', 'created_at.asc', 500, ['id.asc']);
            $ok = 0;
            foreach ($rows as $row) {
                $game = (string) ($row['game_id'] ?? '');
                $giver = (string) ($row['giver_id'] ?? '');
                $receiver = (string) ($row['receiver_id'] ?? '');
                $badge = (string) ($row['badge'] ?? '');
                if ($game === '' || $giver === '' || $receiver === '' || $badge === '') {
                    continue;
                }
                StreetCred::query()->updateOrCreate(
                    [
                        'game_uuid'     => $game,
                        'giver_uuid'    => $giver,
                        'receiver_uuid' => $receiver,
                        'badge'         => $badge,
                    ],
                    []
                );
                $ok++;
            }
            $this->command?->info("street_cred upserted: {$ok}");
        } catch (Throwable $e) {
            Log::error('CustomerSocialFromSupabaseSeeder street_cred: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->warn('street_cred import failed: ' . $e->getMessage());
        }
    }
}
