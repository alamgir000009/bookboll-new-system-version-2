<?php

/**
 * Next month's slots open once the current month is nearly over, controlled by
 * the days_until_next_month_bookings_open setting.
 *
 * Two bugs made that window wrong, and both were invisible from the outside —
 * the endpoint just said "not yet available" on a date that should have been
 * bookable:
 *
 *  1. The remaining-days count was `$today->diffInDays($today->endOfMonth()) + 1`.
 *     endOfMonth() is 23:59:59 and Carbon 3 returns diffInDays() as a FLOAT, so
 *     Oct 25 -> Oct 31 came back 6.9999... not 6. The +1 (written when Carbon 2
 *     truncated to an int) made it 7.9999, and `7.9999 <= 7` is false — every
 *     threshold opened a full day late, so operators compensated by setting N+1.
 *
 *  2. `(int) Setting::...->value('value') ?? 7` parses as `((int) $x) ?? 7`, and
 *     `(int) null` is 0 — never null. The fallback was unreachable, so a missing
 *     row meant 0, and `$daysRemaining <= 0` is never true: next month never
 *     opened at all instead of defaulting to 7.
 *
 * These pin the boundary day on both sides, because "off by one" is exactly the
 * failure that keeps coming back.
 */

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function windowDays(?string $value): void
{
    Setting::where('key', 'days_until_next_month_bookings_open')->delete();

    if ($value !== null) {
        Setting::create([
            'key'   => 'days_until_next_month_bookings_open',
            'type'  => 'integer',
            'value' => $value,
        ]);
    }
}

/** Is the 1st of next month bookable, as of $today? */
function nextMonthOpenOn(string $today): bool
{
    Carbon::setTestNow(Carbon::parse($today));

    return isDateAvailableForBooking(
        Carbon::parse($today)->addMonthNoOverflow()->startOfMonth()
    );
}

it('opens next month on the day the remaining-day count reaches the threshold', function () {
    windowDays('7');

    // Oct has 31 days: on the 25th, 7 days remain counting today (25..31).
    expect(nextMonthOpenOn('2026-10-25'))->toBeTrue();
    // On the 24th, 8 remain — one day too early.
    expect(nextMonthOpenOn('2026-10-24'))->toBeFalse();
});

it('stays open for the rest of the month once the window opens', function () {
    windowDays('7');

    foreach (['2026-10-26', '2026-10-28', '2026-10-31'] as $day) {
        expect(nextMonthOpenOn($day))->toBeTrue("expected $day to be inside the window");
    }
});

it('counts the real length of short and leap months', function () {
    windowDays('7');

    expect(nextMonthOpenOn('2027-02-22'))->toBeTrue();   // Feb 28: 22..28 = 7
    expect(nextMonthOpenOn('2027-02-21'))->toBeFalse();  // 8 remain
    expect(nextMonthOpenOn('2028-02-23'))->toBeTrue();   // leap Feb 29: 23..29 = 7
    expect(nextMonthOpenOn('2026-11-24'))->toBeTrue();   // Nov 30: 24..30 = 7
});

it('opens January from December across the year boundary', function () {
    windowDays('7');

    expect(nextMonthOpenOn('2026-12-25'))->toBeTrue();
    expect(nextMonthOpenOn('2026-12-24'))->toBeFalse();
});

it('falls back to 7 days when the setting is missing or blank', function () {
    foreach ([null, ''] as $value) {
        windowDays($value);

        // The whole point of the fallback: an absent row must not mean "never".
        expect(nextMonthBookingsOpenDays())->toBe(7);
        expect(nextMonthOpenOn('2026-10-25'))->toBeTrue();
        expect(nextMonthOpenOn('2026-10-24'))->toBeFalse();
    }
});

it('honours an explicit zero as closed, and a full month as always open', function () {
    windowDays('0');
    expect(nextMonthOpenOn('2026-10-31'))->toBeFalse();

    windowDays('31');
    expect(nextMonthOpenOn('2026-10-01'))->toBeTrue();
});

it('leaves dates outside next month alone', function () {
    windowDays('7');
    Carbon::setTestNow(Carbon::parse('2026-10-25'));

    expect(isDateAvailableForBooking(Carbon::parse('2026-10-30')))->toBeTrue();   // this month
    expect(isDateAvailableForBooking(Carbon::parse('2026-10-25')))->toBeTrue();   // today
    expect(isDateAvailableForBooking(Carbon::parse('2026-10-24')))->toBeFalse();  // past
    expect(isDateAvailableForBooking(Carbon::parse('2026-12-01')))->toBeFalse();  // month + 2
});
