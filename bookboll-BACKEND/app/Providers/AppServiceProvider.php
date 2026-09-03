<?php
namespace App\Providers;

use App\Observers\ClassSubscriptionObserver;
use App\Observers\BookingObserver;
use App\Models\ClassSubscription;
use App\Models\Booking;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Paginator::useTailwind();

        Booking::observe(BookingObserver::class);
        ClassSubscription::observe(ClassSubscriptionObserver::class);

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
