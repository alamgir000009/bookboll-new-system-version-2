<?php

namespace Database\Seeders;

use App\Models\ProviderWallet;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull provider_wallets from Supabase into MySQL.
 *
 *   php artisan db:seed --class=ProviderWalletsFromSupabaseSeeder
 */
class ProviderWalletsFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->warn($e->getMessage());
            $this->call(WalletPayoutDemoSeeder::class);

            return;
        }

        try {
            $this->command?->info('Fetching provider_wallets…');
            $rows = $client->fetchAll('provider_wallets', 'created_at.asc');
            $ok = 0;
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $owner = (string) ($row['owner_user_id'] ?? $row['provider_id'] ?? '');
                if ($id === '' || $owner === '') {
                    continue;
                }
                ProviderWallet::query()->updateOrCreate(
                    ['id' => $id],
                    $this->mapRow($row, $owner)
                );
                $ok++;
            }
            $this->command?->info("provider_wallets upserted: {$ok}");
            if ($ok === 0) {
                $this->call(WalletPayoutDemoSeeder::class);
            }
        } catch (Throwable $e) {
            Log::error('ProviderWalletsFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->warn($e->getMessage());
            $this->call(WalletPayoutDemoSeeder::class);
        }
    }

    protected function mapRow(array $row, string $owner): array
    {
        $holder = $row['payout_account_holder'] ?? $row['payout_account_name'] ?? null;

        return [
            'owner_user_id'          => $owner,
            'available_balance'      => $row['available_balance'] ?? 0,
            'pending_balance'        => $row['pending_balance'] ?? 0,
            'lifetime_earnings'      => $row['lifetime_earnings'] ?? 0,
            'lifetime_withdrawn'     => $row['lifetime_withdrawn'] ?? 0,
            'currency'               => $row['currency'] ?? 'AED',
            'hold_days'              => $row['hold_days'] ?? 7,
            'setup_method'           => $row['setup_method'] ?? null,
            'setup_status'           => $row['setup_status'] ?? null,
            'setup_completed_at'     => $row['setup_completed_at'] ?? null,
            'country_id'             => isset($row['country_id']) ? (string) $row['country_id'] : null,
            'payout_bank_country'    => $row['payout_bank_country'] ?? null,
            'payout_bank_name'       => $row['payout_bank_name'] ?? null,
            'payout_account_holder'  => $holder,
            'payout_iban'            => $row['payout_iban'] ?? null,
            'payout_account_number'  => $row['payout_account_number'] ?? null,
            'payout_routing_number'  => $row['payout_routing_number'] ?? null,
            'payout_swift'           => $row['payout_swift'] ?? null,
            'payout_account_type'    => $row['payout_account_type'] ?? null,
            'is_current'             => array_key_exists('is_current', $row) ? (bool) $row['is_current'] : true,
            'status'                 => $row['status'] ?? 'current',
            'purpose'                => $row['purpose'] ?? 'subscription',
            'kyc_business_name'      => $row['kyc_business_name'] ?? null,
            'kyc_registration_no'    => $row['kyc_registration_no'] ?? null,
            'kyc_address'            => $row['kyc_address'] ?? null,
            'kyc_contact'            => $row['kyc_contact'] ?? null,
            'kyc_doc_url'            => $row['kyc_doc_url'] ?? null,
            'kyc_submitted_at'       => $row['kyc_submitted_at'] ?? null,
            'kyc_reviewed_at'        => $row['kyc_reviewed_at'] ?? null,
            'kyc_reviewed_by'        => isset($row['kyc_reviewed_by']) ? (string) $row['kyc_reviewed_by'] : null,
            'kyc_rejection_reason'   => $row['kyc_rejection_reason'] ?? null,
            'payout_paused'          => (bool) ($row['payout_paused'] ?? false),
            'payout_paused_at'       => $row['payout_paused_at'] ?? null,
            'payout_pause_reason'    => $row['payout_pause_reason'] ?? null,
            'created_at'             => $row['created_at'] ?? now(),
            'updated_at'             => $row['updated_at'] ?? now(),
        ];
    }
}
