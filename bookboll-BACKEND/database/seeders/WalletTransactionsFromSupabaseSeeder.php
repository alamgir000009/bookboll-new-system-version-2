<?php

namespace Database\Seeders;

use App\Models\ProviderWallet;
use App\Models\WalletTransaction;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pull wallet_transactions from Supabase into MySQL.
 *
 *   php artisan db:seed --class=WalletTransactionsFromSupabaseSeeder
 */
class WalletTransactionsFromSupabaseSeeder extends Seeder
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
            $this->command?->warn('SUPABASE_SERVICE_ROLE_KEY empty — wallet_transactions may return 0 under RLS.');
        }

        try {
            $this->command?->info('Fetching wallet_transactions…');
            $rows = $client->fetchAll('wallet_transactions', 'created_at.desc', 500, ['id.asc']);
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
                    $skipped++;
                    continue;
                }
                WalletTransaction::query()->updateOrCreate(
                    ['id' => $id],
                    [
                        'owner_user_id'      => $owner,
                        'type'               => $row['type'] ?? 'booking_online',
                        'direction'          => $row['direction'] ?? 'credit',
                        'amount'             => $row['amount'] ?? 0,
                        'provider_share'     => $row['provider_share'] ?? 0,
                        'platform_fee'       => $row['platform_fee'] ?? 0,
                        'source_id'          => isset($row['source_id']) ? (string) $row['source_id'] : null,
                        'source_label'       => $row['source_label'] ?? '',
                        'description'        => $row['description'] ?? '',
                        'status'             => $row['status'] ?? 'completed',
                        'currency'           => $row['currency'] ?? 'AED',
                        'available_on'       => $row['available_on'] ?? null,
                        'hold_days_snapshot' => $row['hold_days_snapshot'] ?? null,
                        'released_at'        => $row['released_at'] ?? null,
                        'country_id'         => isset($row['country_id']) ? (string) $row['country_id'] : null,
                        'wallet_id'          => $walletId,
                        'created_at'         => $row['created_at'] ?? now(),
                    ]
                );
                $ok++;
            }
            $this->command?->info("wallet_transactions upserted: {$ok} (skipped {$skipped})");
        } catch (Throwable $e) {
            Log::error('WalletTransactionsFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->warn($e->getMessage());
        }
    }
}
