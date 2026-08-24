<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Import hosted_games + game_join_requests from Supabase.
 *
 *   php artisan db:seed --class=EventModerationFromSupabaseSeeder
 */
class EventModerationFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('=== Event moderation import from Supabase ===');
        $this->command?->info('1/2 hosted_games…');
        $this->call(HostedGamesFromSupabaseSeeder::class);
        $this->command?->info('2/2 game_join_requests…');
        $this->call(GameJoinRequestsFromSupabaseSeeder::class);
        $this->command?->info('=== Done. Communities stay on Supabase. ===');
    }
}
