<?php

use App\Support\Money;
use App\Support\RentProration as Rent;

/**
 * Rent accrues per calendar day, at the monthly amount over the days in THAT
 * month (readmeAlamgir §2). These pin the worked examples it gives.
 */

$qar = fn ($n) => Money::toMinor($n, 'QAR');

it('charges the document own October example: 15 x 100 + 16 x 200 = 4,700', function () use ($qar) {
    // 3,100 across a 31-day October is 100/day; 6,200 is 200/day.
    $total = Rent::monthFromSegments('2026-10', [
        ['monthly_minor' => $qar(3100), 'from' => '2026-10-01', 'to' => '2026-10-15'],
        ['monthly_minor' => $qar(6200), 'from' => '2026-10-16', 'to' => '2026-10-31'],
    ]);

    expect(Money::toMajor($total, 'QAR'))->toBe(4700.0);
});

it('charges a full month exactly, with no rounding drift', function (array $case) use ($qar) {
    [$monthly, $from, $to] = $case;
    $months = Rent::monthly($qar($monthly), $from, $to);

    expect(Money::toMajor(array_sum($months), 'QAR'))->toBe((float) $monthly);
})->with([
    '31-day month'    => [[3100, '2026-01-01', '2026-01-31']],
    '30-day month'    => [[1000, '2026-04-01', '2026-04-30']],  // 1000/30 is not exact
    'february'        => [[2800, '2026-02-01', '2026-02-28']],
    'leap february'   => [[2900, '2024-02-01', '2024-02-29']],
    'awkward amount'  => [[999.99, '2026-07-01', '2026-07-31']],
]);

it('uses each month own day count, so February is dearer per day', function () use ($qar) {
    $jan = Rent::total($qar(3100), '2026-01-01', '2026-01-10');   // 100/day
    $feb = Rent::total($qar(3100), '2026-02-01', '2026-02-10');   // 3100/28 per day

    expect(Money::toMajor($jan, 'QAR'))->toBe(1000.0)
        ->and($feb)->toBeGreaterThan($jan);
});

it('accrues ten active days of a 31-day month at 100 a day', function () use ($qar) {
    // The document's other example: rent 3,100 in a 31-day month -> 100/day
    // even with no bookings at all; 10 active days -> 1,000.
    expect(Money::toMajor(Rent::total($qar(3100), '2026-01-01', '2026-01-10'), 'QAR'))
        ->toBe(1000.0);
});

it('counts both end dates, because the agreement is written inclusive', function () use ($qar) {
    // 1st to 1st is one day, not zero.
    expect(Money::toMajor(Rent::total($qar(3100), '2026-01-01', '2026-01-01'), 'QAR'))
        ->toBe(100.0);
});

it('splits across months at the month boundary', function () use ($qar) {
    $months = Rent::monthly($qar(3000), '2026-01-30', '2026-02-02');

    // Jan has 31 days (3000/31 x 2), Feb has 28 (3000/28 x 2).
    expect(array_keys($months))->toBe(['2026-01', '2026-02'])
        ->and($months['2026-01'])->toBeLessThan($months['2026-02']);
});

it('accrues over a whole year without losing or inventing a unit', function () use ($qar) {
    $total = Rent::total($qar(1000), '2026-01-01', '2026-12-31');

    // Twelve whole months, exactly.
    expect(Money::toMajor($total, 'QAR'))->toBe(12000.0);
});

it('owes nothing for an inverted range or a zero rent', function () use ($qar) {
    expect(Rent::total($qar(3100), '2026-10-31', '2026-10-01'))->toBe(0)
        ->and(Rent::total(0, '2026-10-01', '2026-10-31'))->toBe(0);
});

it('ignores segments that fall outside the month asked about', function () use ($qar) {
    $total = Rent::monthFromSegments('2026-10', [
        ['monthly_minor' => $qar(3100), 'from' => '2026-09-01', 'to' => '2026-09-30'],
        ['monthly_minor' => $qar(3100), 'from' => '2026-11-01', 'to' => '2026-11-30'],
    ]);

    expect($total)->toBe(0);
});

it('clips a segment that overruns the month on both sides', function () use ($qar) {
    // An agreement running all year, asked about October only.
    $total = Rent::monthFromSegments('2026-10', [
        ['monthly_minor' => $qar(3100), 'from' => '2026-01-01', 'to' => '2026-12-31'],
    ]);

    expect(Money::toMajor($total, 'QAR'))->toBe(3100.0);
});
