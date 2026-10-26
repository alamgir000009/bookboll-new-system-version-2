<?php

/**
 * "Closed on this date."
 *
 * Next month's calendar is released a few days before the current month ends
 * (days_until_next_month_bookings_open). Before that, asking for a November day
 * in early October is not a CLOSURE — the slots simply are not out yet. The gate
 * answered with its own rule, "Next month slots become available when there are
 * 7 or fewer days remaining in the current month", which asks the reader to do
 * the arithmetic the server already did; and the storefront showed none of it,
 * collapsing any failed reply into the flat word "Closed", which reads as "this
 * venue never opens then".
 *
 * Three different situations land here and only one of them is about next month,
 * so each gets its own sentence — plus `reason`/`opens_on` so a client never has
 * to parse English to know which it was.
 */

use App\Models\LoyaltyTier;
use Carbon\Carbon;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);

    ['listings' => $listings] = courtPool(4, ['court' => ['max' => 1]]);
    $this->listing = $listings['court'];
});

/** Ask the storefront for a day's slots. */
function slotsOn(string $date)
{
    return test()->withHeader('from', 'frontend-web')
        ->getJson("/api/fetch-time-slots?listing_id=" . test()->listing->id . "&date={$date}");
}

afterEach(fn () => Carbon::setTestNow());

it('opens next month on the day the gate itself lifts', function () {
    // The helper must be derived from isDateAvailableForBooking(), not restated:
    // a promised date that differs from the real one is worse than no date.
    Carbon::setTestNow(Carbon::parse('2026-10-10'));

    $opensOn = nextMonthBookingsOpenOn();

    expect(isDateAvailableForBooking(Carbon::parse('2026-11-05')))->toBeFalse()
        ->and($opensOn->toDateString())->toBe('2026-10-25');

    // On that exact day the gate is open, and not a day before.
    Carbon::setTestNow($opensOn->copy()->subDay());
    expect(isDateAvailableForBooking(Carbon::parse('2026-11-05')))->toBeFalse();

    Carbon::setTestNow($opensOn);
    expect(isDateAvailableForBooking(Carbon::parse('2026-11-05')))->toBeTrue();
});

it('tells a shopper when next month goes live instead of calling it closed', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10'));

    $response = slotsOn('2026-11-05');

    expect($response->json('message'))
        ->toContain('November slots are not open yet')
        ->toContain('25 Oct')
        ->toContain('Keep an eye')
        ->not->toContain('Closed')
        ->not->toContain('fewer days remaining');
});

it('carries the reason and opening date as data, not just prose', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10'));

    slotsOn('2026-11-05')
        ->assertJsonPath('errors.reason', 'not_open_yet')
        ->assertJsonPath('errors.opens_on', '2026-10-25');
});

it('says something different for a date beyond next month', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10'));

    expect(slotsOn('2026-12-05')->json('message'))
        ->toContain('one month at a time')
        ->toContain('December 2026')
        ->not->toContain('not open yet');
});

it('leaves a past date to the validator that already explains it', function () {
    // `date.after_or_equal` catches this before the release gate is consulted,
    // and already says it plainly. Pinned so the new wording never starts
    // competing with it.
    Carbon::setTestNow(Carbon::parse('2026-10-10'));

    expect(slotsOn('2026-10-01')->json('message'))
        ->toContain('past date')
        ->not->toContain('not open yet');
});

it('stops saying any of it once next month is released', function () {
    // Same November day, asked for after the gate lifts.
    Carbon::setTestNow(Carbon::parse('2026-10-26'));

    expect(slotsOn('2026-11-05')->json('message'))
        ->not->toContain('not open yet');
});

it('follows the configured release window', function () {
    \App\Models\Setting::updateOrCreate(
        ['key' => 'days_until_next_month_bookings_open'],
        ['value' => '3']
    );
    Carbon::setTestNow(Carbon::parse('2026-10-10'));

    // October has 31 days, so three days remaining means the 29th.
    expect(nextMonthBookingsOpenOn()->toDateString())->toBe('2026-10-29')
        ->and(slotsOn('2026-11-05')->json('message'))->toContain('29 Oct');
});
