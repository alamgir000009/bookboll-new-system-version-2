<?php

namespace Database\Seeders;

use App\Models\CustomType;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pull custom_types from Supabase (or invent pending rows from ApprovalsDemoSeeder).
 *
 *   php artisan db:seed --class=CustomTypesFromSupabaseSeeder
 */
class CustomTypesFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->warn($e->getMessage());
            $this->seedFallback();

            return;
        }

        try {
            $this->command?->info('Fetching custom_types…');
            $rows = $client->fetchAll('custom_types', 'created_at.asc');
            $ok = 0;
            foreach ($rows as $row) {
                $domain = (string) ($row['domain'] ?? '');
                $name = trim((string) ($row['name'] ?? ''));
                $category = trim((string) ($row['category'] ?? ''));
                if ($domain === '' || $name === '') {
                    continue;
                }
                $id = (string) ($row['id'] ?? Str::uuid());
                CustomType::query()->updateOrCreate(
                    ['uuid' => $id],
                    [
                        'domain'      => $domain,
                        'name'        => $name,
                        'category'    => $category ?: null,
                        'status'      => ($row['status'] ?? 'pending') === 'approved' ? 'approved' : 'pending',
                        'created_by'  => isset($row['created_by']) ? (string) $row['created_by'] : null,
                        'approved_at' => $row['approved_at'] ?? null,
                    ]
                );
                $ok++;
            }
            $this->command?->info("custom_types upserted: {$ok}");
            if ($ok === 0) {
                $this->seedFallback();
            }
        } catch (Throwable $e) {
            Log::error('CustomTypesFromSupabaseSeeder failed: ' . $e->getMessage(), ['exception' => $e]);
            $this->command?->warn($e->getMessage());
            $this->seedFallback();
        }
    }

    protected function seedFallback(): void
    {
        CustomType::query()->updateOrCreate(
            ['uuid' => 'cccccccc-dddd-eeee-ffff-000000000001'],
            [
                'domain'     => CustomType::DOMAIN_VENUE,
                'name'       => 'Futsal Court XL',
                'category'   => 'sports',
                'status'     => CustomType::STATUS_PENDING,
                'created_by' => null,
            ]
        );
        CustomType::query()->updateOrCreate(
            ['uuid' => 'cccccccc-dddd-eeee-ffff-000000000002'],
            [
                'domain'     => CustomType::DOMAIN_PROGRAM,
                'name'       => 'Kids Academy Elite',
                'category'   => 'academy',
                'status'     => CustomType::STATUS_PENDING,
                'created_by' => null,
            ]
        );
        $this->command?->info('custom_types demo pending rows ready.');
    }
}
