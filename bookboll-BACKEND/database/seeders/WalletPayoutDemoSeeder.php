<?php

namespace Database\Seeders;

use App\Models\ProviderWallet;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo wallets + pending KYC / withdrawal when Supabase is unreachable.
 *
 *   php artisan db:seed --class=WalletPayoutDemoSeeder
 */
class WalletPayoutDemoSeeder extends Seeder
{
    public function run(): void
    {
        $owner = 'dddddddd-eeee-ffff-aaaa-000000000001';
        $country = 'dddddddd-eeee-ffff-bbbb-000000000001';
        $walletId = 'dddddddd-eeee-ffff-cccc-000000000001';
        $walletId2 = 'dddddddd-eeee-ffff-cccc-000000000002';

        $wallet = ProviderWallet::query()->updateOrCreate(
            ['uuid' => $walletId],
            [
                'owner_user_id'         => $owner,
                'country_id'            => $country,
                'currency'              => 'AED',
                'available_balance'     => 250.00,
                'pending_balance'       => 100.00,
                'lifetime_earnings'     => 500.00,
                'lifetime_withdrawn'    => 150.00,
                'hold_days'             => 7,
                'setup_method'          => 'bookboll',
                'setup_status'          => 'pending_review',
                'purpose'               => 'subscription',
                'is_current'            => true,
                'status'                => 'current',
                'kyc_business_name'     => 'Demo Sports LLC',
                'kyc_registration_no'   => 'LIC-DEMO-001',
                'kyc_address'           => '123 Demo Street, Dubai',
                'kyc_contact'           => '+971500000000',
                'kyc_submitted_at'      => now()->subDay(),
                'payout_bank_name'      => 'Demo Bank',
                'payout_account_holder' => 'Demo Sports LLC',
                'payout_iban'           => 'AE070331234567890123456',
                'payout_bank_country'   => 'AE',
            ]
        );

        ProviderWallet::query()->updateOrCreate(
            ['uuid' => $walletId2],
            [
                'owner_user_id'         => $owner,
                'country_id'            => (string) Str::uuid(),
                'currency'              => 'AED',
                'available_balance'     => 50.00,
                'pending_balance'       => 0,
                'lifetime_earnings'     => 50.00,
                'lifetime_withdrawn'    => 0,
                'setup_method'          => 'bookboll',
                'setup_status'          => 'pending_review',
                'purpose'               => 'bookings',
                'is_current'            => true,
                'status'                => 'current',
                'kyc_business_name'     => 'Demo Sports LLC',
                'payout_bank_name'      => 'Demo Bank',
                'payout_account_holder' => 'Demo Sports LLC',
                'payout_iban'           => 'AE070331234567890123456',
            ]
        );

        WithdrawalRequest::query()->updateOrCreate(
            ['uuid' => 'dddddddd-eeee-ffff-dddd-000000000001'],
            [
                'owner_user_id'  => $owner,
                'amount'         => 100.00,
                'currency'       => 'AED',
                'payout_method'  => 'bank',
                'status'         => 'pending',
                'country_id'     => $country,
                'wallet_id'      => $wallet->id,
                'requested_at'   => now()->subHours(3),
            ]
        );

        $this->command?->info('Wallet payout demo: 2 pending_review wallets + 1 pending withdrawal.');
    }
}
