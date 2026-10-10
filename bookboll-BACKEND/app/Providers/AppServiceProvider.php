<?php
namespace App\Providers;

use App\Observers\ClassSubscriptionObserver;
use App\Support\TenantOrigins;
use App\Observers\BookingObserver;
use App\Models\ClassSubscription;
use App\Models\Booking;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Paginator::useTailwind();

        Booking::observe(BookingObserver::class);
        ClassSubscription::observe(ClassSubscriptionObserver::class);

        Gate::define('viewLogViewer', function ($user = null) {
            return $user?->hasRole('super_admin') ?? false;
        });

        $key    = trim((string) config('services.stripe.key'));
        $secret = trim((string) config('services.stripe.secret'));

        if ($key !== '') {
            config(['cashier.key' => $key]);
        }

        if ($secret !== '') {
            config(['cashier.secret' => $secret]);
        }

        $this->allowCorsFromProviderDomains();
    }

    /**
     * Let storefronts on a provider's own domain call the API from a browser.
     *
     * config/cors.php can pattern-match *.bookboll.com but not the domains providers
     * buy and connect at runtime, so without this a paid custom domain is blocked by
     * CORS on every request — including the Google sign-in handshake, which is half
     * the reason the broker exists.
     *
     * Read from the same cached list as RestrictApiOrigin, so "allowed to call us" and
     * "allowed to be sent a session" cannot drift apart. Failure is swallowed on
     * purpose: a cache or database hiccup must not take down every CORS preflight, and
     * the configured origins still work.
     */
    private function allowCorsFromProviderDomains(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            $origins = array_map(fn ($domain) => 'https://'.$domain, TenantOrigins::customDomains());

            if ($origins !== []) {
                config(['cors.allowed_origins' => array_values(array_unique(
                    array_merge(config('cors.allowed_origins', []), $origins)
                ))]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
