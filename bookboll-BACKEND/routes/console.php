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

// Stripe's hosted invoice/receipt link for payments it collected. Membership
// invoices store theirs straight from the webhook; one-off charges are resolved
// here instead of during checkout, so a booking can never fail because a Stripe
// lookup did. Hourly means a new charge has its receipt within the hour, and
// the first run fills in history.
Schedule::command('payments:backfill-stripe-urls')->hourly()->withoutOverlapping();

// Upload records whose file is already gone. Deletes no files, so it is safe to
// run unattended. The unreferenced-file sweep is deliberately NOT scheduled:
// it needs --delete-unreferenced and a human who has read its --dry-run first.
Schedule::command('uploads:prune')->dailyAt('03:10');
