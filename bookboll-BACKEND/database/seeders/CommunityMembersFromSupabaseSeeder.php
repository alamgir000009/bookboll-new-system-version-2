<?php

namespace Database\Seeders;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull community_members from Supabase into MySQL.
 *
 *   php artisan db:seed --class=CommunityMembersFromSupabaseSeeder
 */
class CommunityMembersFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->error($e->getMessage());
            $this->command?->warn('Skipping community_members import.');

            return;
        }

        if (! $client->usingServiceRole()) {
            $this->command?->warn(
                'SUPABASE_SERVICE_ROLE_KEY empty — community_members may return 0 under RLS.'
            );
        }

        try {
            $this->command?->info('Fetching community_members…');
            $rows = $client->fetchAll('community_members', 'joined_at.desc', 500, ['joined_at.asc', 'id.asc']);
            $communityIdsByUuid = Community::query()->pluck('id', 'uuid');
            $ok = 0;
            $skipped = 0;
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $cid = (string) ($row['community_id'] ?? '');
                $customer = (string) ($row['customer_id'] ?? '');
                $communityPk = $cid !== '' ? $communityIdsByUuid->get($cid) : null;
                if ($id === '' || ! $communityPk || $customer === '') {
                    $skipped++;
                    continue;
                }
                CommunityMember::query()->updateOrCreate(
                    ['uuid' => $id],
                    [
                        'community_id' => (int) $communityPk,
                        'customer_id'  => $customer,
                        'role'         => (string) ($row['role'] ?? 'member'),
                        'status'       => (string) ($row['status'] ?? 'accepted'),
                        'last_read_at' => $row['last_read_at'] ?? null,
                        'joined_at'    => $row['joined_at'] ?? now(),
                        'created_at'   => $row['joined_at'] ?? $row['created_at'] ?? now(),
                    ]
                );
                $ok++;
            }
            $this->command?->info("community_members upserted: {$ok} (skipped {$skipped})");
        } catch (Throwable $e) {
            Log::error('CommunityMembersFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->error($e->getMessage());
        }
    }
}
