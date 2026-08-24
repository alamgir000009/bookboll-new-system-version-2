<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Import communities + members + posts from Supabase.
 *
 *   php artisan db:seed --class=CommunityModerationFromSupabaseSeeder
 */
class CommunityModerationFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('=== Community moderation import from Supabase ===');
        $this->command?->info('1/3 communities…');
        $this->call(CommunitiesFromSupabaseSeeder::class);
        $this->command?->info('2/3 community_members…');
        $this->call(CommunityMembersFromSupabaseSeeder::class);
        $this->command?->info('3/3 community_posts…');
        $this->call(CommunityPostsFromSupabaseSeeder::class);
        $this->command?->info('=== Done. ===');
    }
}
