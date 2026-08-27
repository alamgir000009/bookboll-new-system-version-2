<?php
namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Paginator::useTailwind();

        $key    = trim((string) config('services.stripe.key'));
        $secret = trim((string) config('services.stripe.secret'));

        if ($key !== '') {
            config(['cashier.key' => $key]);
        }

        if ($secret !== '') {
            config(['cashier.secret' => $secret]);
        }
    }
}
