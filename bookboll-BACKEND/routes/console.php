<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('slot-locks:clear')->everyFiveMinutes();

Schedule::command('send-booking-reminders')->daily();

Schedule::command('bookings:auto-cancel-unpaid-deposit')->everyMinute();

// The same timeout, for hosted events whose host walked away from Stripe: their
// courts have to go back on sale like any other unpaid booking's would.
Schedule::command('games:discard-unpaid --apply')->everyMinute()->withoutOverlapping();

// Provider earnings sit in `pending_balance` until their hold period is up.
// Without this they never reach `available_balance`, so the wallet reads empty
// and no withdrawal can ever be requested against the money.
Schedule::command('wallet:release-holds --apply')->dailyAt('02:00');

Schedule::command('backup:run --only-db')->daily()->at('01:30');
