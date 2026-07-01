<?php
namespace App\Providers;

use App\Models\Setting;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Paginator::useTailwind();

        if (app()->runningInConsole()) {
            return;
        }

        if (Schema::hasTable('settings')) {
            $settings = Setting::whereIn('key', [
                'stripe_publishable_key',
                'stripe_secret_key',
                'stripe_webhook_secret',
            ])->pluck('value', 'key');

            config([
                'services.stripe.key'            => $settings['stripe_publishable_key'] ?? null,
                'services.stripe.secret'         => $settings['stripe_secret_key'] ?? null,
                'services.stripe.webhook_secret' => $settings['stripe_webhook_secret'] ?? null,
            ]);

            config([
                'cashier.key'    => $settings['stripe_publishable_key'] ?? null,
                'cashier.secret' => $settings['stripe_secret_key'] ?? null,
            ]);
        }

        if (config('cashier.secret') === null) {
            config(['cashier.secret' => env('STRIPE_SECRET')]);
            config(['cashier.key' => env('STRIPE_KEY')]);
        }
    }
}
