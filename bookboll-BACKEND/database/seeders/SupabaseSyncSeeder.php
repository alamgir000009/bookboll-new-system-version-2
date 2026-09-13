<?php

namespace Database\Seeders;

use App\Models\Venue;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Full Supabase → MySQL sync, in dependency order.
 *
 * Each step looks its parents up and SKIPS rows whose parent is missing, so the
 * order below matters:
 *   • users.uuid must be backfilled first — every later step keys off it.
 *   • provider_wallets before class_subscriptions (wallet_id lookup).
 *   • communities before hosted_games (community_id lookup).
 *   • hosted_games before game_join_requests.
 *
 * Venues/listings must already exist locally — ListingsApprovals and
 * ClassMembership match venues BY NAME and skip what they cannot resolve.
 *
 * Requires SUPABASE_URL + SUPABASE_SERVICE_ROLE_KEY in .env (the anon key hits
 * RLS and returns 0 rows for most tables).
 *
 *   php artisan db:seed --class=SupabaseSyncSeeder
 */
class SupabaseSyncSeeder extends Seeder
{
    /**
     * Ordered steps: [label, seeder class].
     *
     * @var list<array{0: string, 1: class-string<Seeder>}>
     */
    protected array $steps = [
        ['users.uuid backfill',              BackfillUserUuidsFromSupabaseSeeder::class],
        ['brand settings',                   BrandSettingsFromSupabaseSeeder::class],
        ['custom types',                     CustomTypesFromSupabaseSeeder::class],
        ['listings (pending approvals)',     ListingsApprovalsFromSupabaseSeeder::class],
        // Imports the Supabase `locations` that class programs point at, with their
        // opening hours. Must precede ClassMembership, which matches venues BY NAME
        // and silently skips programs whose venue is missing.
        ['program venues',                   ProgramVenuesFromSupabaseSeeder::class],
        ['wallets → withdrawals → ledger',   WalletPayoutFromSupabaseSeeder::class],
        ['class programs & subscriptions',   ClassMembershipFromSupabaseSeeder::class],
        ['communities, members, posts',      CommunityModerationFromSupabaseSeeder::class],
        ['hosted games & join requests',     EventModerationFromSupabaseSeeder::class],
        ['customer social (follows/posts)',  CustomerSocialFromSupabaseSeeder::class],
    ];

    public function run(): void
    {
        $this->command?->info('=== Supabase → MySQL sync ===');

        if (! $this->preflight()) {
            return;
        }

        $total = count($this->steps);

        foreach ($this->steps as $i => [$label, $class]) {
            $this->command?->info(sprintf('%d/%d %s…', $i + 1, $total, $label));
            $this->call($class);
        }

        $this->command?->info('=== Done. ===');
    }

    /** Fail fast on a bad Supabase config instead of nine identical warnings. */
    protected function preflight(): bool
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->error($e->getMessage());
            $this->command?->warn('Aborting sync — nothing was imported.');

            return false;
        }

        if (! $client->usingServiceRole()) {
            $this->command?->warn(
                'SUPABASE_SERVICE_ROLE_KEY is empty — using the anon key. RLS will return 0 rows for most tables.'
            );
        }

        if (Venue::query()->doesntExist()) {
            $this->command?->warn(
                'No venues in the local DB — listings and class programs match venues by name and will be skipped.'
            );
        }

        return true;
    }
}
