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
