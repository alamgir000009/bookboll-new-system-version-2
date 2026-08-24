<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copy brand_* / admin_* keys from Supabase platform_settings into Laravel settings.
 * Skips keys that already have a non-empty value in Laravel.
 *
 * Requires SUPABASE_URL + SUPABASE_SERVICE_ROLE_KEY.
 *
 *   php artisan db:seed --class=BrandSettingsFromSupabaseSeeder
 */
class BrandSettingsFromSupabaseSeeder extends Seeder
{
    /** @var list<string> */
    protected array $keys = [
        'brand_name',
        'brand_tagline',
        'brand_logo_url',
        'brand_primary',
        'brand_accent',
        'brand_meta_title',
        'brand_meta_description',
        'admin_logo_url',
        'admin_primary',
    ];

    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->warn($e->getMessage());

            return;
        }

        try {
            $this->command?->info('Fetching platform_settings brand keys…');
            $rows = $client->fetchAll('platform_settings', 'key.asc');
            $byKey = [];
            foreach ($rows as $row) {
                $k = (string) ($row['key'] ?? '');
                if ($k !== '' && in_array($k, $this->keys, true)) {
                    $byKey[$k] = $row['value'] ?? null;
                }
            }

            $copied = 0;
            $skipped = 0;
            foreach ($this->keys as $key) {
                if (! array_key_exists($key, $byKey)) {
                    continue;
                }
                $incoming = $byKey[$key];
                if ($incoming === null || $incoming === '') {
                    $skipped++;
                    continue;
                }

                $existing = Setting::query()->where('key', $key)->first();
                if ($existing && $existing->value !== null && $existing->value !== '') {
                    // Keep non-empty Laravel value (migration defaults like brand_name=Bookboll count).
                    // Still allow overwrite when Laravel only has the generic default name and Supabase has a different brand_name.
                    if ($key !== 'brand_name' || (string) $existing->value !== 'Bookboll') {
                        $skipped++;
                        continue;
                    }
                    if ((string) $incoming === 'Bookboll') {
                        $skipped++;
                        continue;
                    }
                }

                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value'       => is_bool($incoming) ? ($incoming ? '1' : '0') : (string) $incoming,
                        'type'        => $key === 'brand_meta_description' ? 'text' : 'string',
                        'description' => $existing?->description,
                        'encrypted'   => false,
                    ]
                );
                $copied++;
            }

            $this->command?->info("Brand settings copied: {$copied}, skipped: {$skipped}");
        } catch (Throwable $e) {
            Log::error('BrandSettingsFromSupabaseSeeder failed: '.$e->getMessage(), ['exception' => $e]);
            $this->command?->warn($e->getMessage());
        }
    }
}
