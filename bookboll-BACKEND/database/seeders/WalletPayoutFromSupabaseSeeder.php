<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orchestrator: wallets → withdrawals → transactions (FK order).
 *
 * Requires SUPABASE_SERVICE_ROLE_KEY in .env for RLS-protected tables.
 *
 *   php artisan db:seed --class=WalletPayoutFromSupabaseSeeder
 */
class WalletPayoutFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('=== Wallet payout import from Supabase ===');
        $this->command?->info('1/3 provider_wallets…');
        $this->call(ProviderWalletsFromSupabaseSeeder::class);
        $this->command?->info('2/3 withdrawal_requests…');
        $this->call(WithdrawalRequestsFromSupabaseSeeder::class);
        $this->command?->info('3/3 wallet_transactions…');
        $this->call(WalletTransactionsFromSupabaseSeeder::class);
        $this->command?->info('=== Done. Use WalletPayoutDemoSeeder only if you need mock rows. ===');
    }
}
