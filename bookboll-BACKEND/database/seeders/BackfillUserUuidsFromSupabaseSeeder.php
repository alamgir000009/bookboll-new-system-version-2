<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Backfill users.uuid (and social profile fields) from Supabase customers by email.
 *
 *   php artisan migrate
 *   php artisan db:seed --class=BackfillUserUuidsFromSupabaseSeeder
 */
class BackfillUserUuidsFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        $matched = 0;
        $generated = 0;
        $social = 0;

        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->warn($e->getMessage());
            $this->command?->warn('Skipping Supabase UUID backfill — generating UUIDs for users missing one.');
            $this->generateMissingUuids($generated);

            return;
        }

        if (! $client->usingServiceRole()) {
            $this->command?->warn(
                'SUPABASE_SERVICE_ROLE_KEY is empty — customer import may return 0 rows.'
            );
        }

        try {
            $this->command?->info('Fetching Supabase customers…');
            $rows = $client->fetchAll('customers', 'created_at.asc', 500, ['id.asc']);
            $byEmail = [];
            foreach ($rows as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email !== '') {
                    $byEmail[$email] = $row;
                }
            }

            User::query()->whereRelation('roles', 'name', 'customer')->chunkById(200, function ($users) use ($byEmail, &$matched, &$social) {
                foreach ($users as $user) {
                    $email = strtolower(trim((string) $user->email));
                    $row = $byEmail[$email] ?? null;
                    $patch = [];

                    if ($row) {
                        $patch['uuid'] = (string) ($row['id'] ?? '');
                        if ($patch['uuid'] === '') {
                            continue;
                        }
                        if (array_key_exists('bio', $row)) {
                            $patch['bio'] = (string) ($row['bio'] ?? '');
                        }
                        if (array_key_exists('is_private', $row)) {
                            $patch['is_private'] = (bool) $row['is_private'];
                        }
                        if (empty($user->image) && ! empty($row['avatar_url'])) {
                            $patch['image'] = (string) $row['avatar_url'];
                        }
                        $matched++;
                        if (isset($patch['bio']) || isset($patch['is_private'])) {
                            $social++;
                        }
                    } elseif (empty($user->uuid)) {
                        $patch['uuid'] = (string) Str::uuid();
                    }

                    if ($patch !== []) {
                        $user->update($patch);
                    }

                    if ($row && array_key_exists('photos', $row)) {
                        $photos = is_array($row['photos']) ? $row['photos'] : [];
                        if ($photos !== []) {
                            $user->syncGalleryPhotos(array_values(array_filter($photos)));
                            $social++;
                        }
                    }
                }
            });

            $this->generateMissingUuids($generated);

            $this->command?->info("UUID backfill: {$matched} matched from Supabase, {$social} with social fields, {$generated} newly generated.");
        } catch (Throwable $e) {
            Log::error('BackfillUserUuidsFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->error($e->getMessage());
            $this->generateMissingUuids($generated);
        }
    }

    protected function generateMissingUuids(int &$generated): void
    {
        User::query()
            ->where(function ($q) {
                $q->whereNull('uuid')->orWhere('uuid', '');
            })
            ->chunkById(200, function ($users) use (&$generated) {
                foreach ($users as $user) {
                    $user->update(['uuid' => (string) Str::uuid()]);
                    $generated++;
                }
            });
    }
}
