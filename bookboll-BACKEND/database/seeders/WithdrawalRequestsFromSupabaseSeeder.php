<?php

namespace Database\Seeders;

use App\Models\ProviderWallet;
use App\Models\WithdrawalRequest;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull withdrawal_requests from Supabase into MySQL.
 *
 *   php artisan db:seed --class=WithdrawalRequestsFromSupabaseSeeder
 */
class WithdrawalRequestsFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->error($e->getMessage());

            return;
        }

        if (! $client->usingServiceRole()) {
            $this->command?->warn('SUPABASE_SERVICE_ROLE_KEY empty — withdrawal_requests may return 0 under RLS.');
        }

        try {
            $this->command?->info('Fetching withdrawal_requests…');
            $rows = $client->fetchAll('withdrawal_requests', 'requested_at.desc', 500, ['created_at.desc', 'id.asc']);
            $ok = 0;
            $skipped = 0;
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $owner = (string) ($row['owner_user_id'] ?? $row['provider_id'] ?? '');
                if ($id === '' || $owner === '') {
                    $skipped++;
                    continue;
                }
                $walletId = isset($row['wallet_id']) ? (string) $row['wallet_id'] : null;
                if ($walletId && ! ProviderWallet::query()->where('id', $walletId)->exists()) {
                    $this->command?->warn("Skip withdrawal {$id}: wallet {$walletId} missing locally");
                    $skipped++;
                    continue;
                }
                WithdrawalRequest::query()->updateOrCreate(
                    ['id' => $id],
                    [
                        'owner_user_id'    => $owner,
                        'amount'           => $row['amount'] ?? 0,
                        'currency'         => $row['currency'] ?? 'AED',
                        'payout_method'    => $row['payout_method'] ?? 'bank',
                        'payout_details'   => $row['payout_details'] ?? '',
                        'status'           => $row['status'] ?? 'pending',
                        'rejection_reason' => $row['rejection_reason'] ?? null,
                        'requested_at'     => $row['requested_at'] ?? now(),
                        'processed_at'     => $row['processed_at'] ?? null,
                        'processed_by'     => $row['processed_by'] ?? null,
                        'approved_at'      => $row['approved_at'] ?? null,
                        'cancelled_at'     => $row['cancelled_at'] ?? null,
                        'notes'            => $row['notes'] ?? null,
                        'country_id'       => isset($row['country_id']) ? (string) $row['country_id'] : null,
                        'wallet_id'        => $walletId,
                    ]
                );
                $ok++;
            }
            $this->command?->info("withdrawal_requests upserted: {$ok} (skipped {$skipped})");
        } catch (Throwable $e) {
            Log::error('WithdrawalRequestsFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->warn($e->getMessage());
        }
    }
}
