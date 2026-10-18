<?php

use App\Support\Money;

/**
 * Minor units, per currency. Everything in this codebase used to be
 * `round($amount * 100)`, which is wrong for a third of the currencies the
 * country list can reach.
 */
it('knows how many minor units a currency actually has', function () {
    expect(Money::exponent('QAR'))->toBe(2)
        ->and(Money::exponent('PKR'))->toBe(2)
        ->and(Money::exponent('JPY'))->toBe(0)   // no minor unit
        ->and(Money::exponent('KWD'))->toBe(3)   // three
        ->and(Money::exponent('BHD'))->toBe(3)
        ->and(Money::exponent('ZZZ'))->toBe(2);  // unknown -> the existing assumption
});

it('converts to and from minor units without losing a decimal place', function () {
    expect(Money::toMinor(100, 'QAR'))->toBe(10000)
        ->and(Money::toMinor(1000, 'JPY'))->toBe(1000)      // not 100000
        ->and(Money::toMinor(1.234, 'KWD'))->toBe(1234)
        ->and(Money::toMajor(10000, 'QAR'))->toBe(100.0)
        ->and(Money::toMajor(1000, 'JPY'))->toBe(1000.0);
});

it('charges 7.50 on a 7.5% fee, not 7', function () {
    // The exact case readmeAlamgir calls out. floor() gave 7.
    $total = Money::toMinor(100, 'QAR');

    expect(Money::percentOf($total, 7.5))->toBe(750)
        ->and(Money::toMajor(750, 'QAR'))->toBe(7.5);
});

it('splits so the fee and the remainder always rebuild the total', function (array $case) {
    [$amount, $pct, $currency] = $case;
    $minor = Money::toMinor($amount, $currency);
    $split = Money::split($minor, $pct);

    expect($split['fee'] + $split['remainder'])->toBe($minor)
        ->and($split['fee'])->toBeGreaterThanOrEqual(0)
        ->and($split['remainder'])->toBeGreaterThanOrEqual(0);
})->with([
    [[100, 20, 'QAR']], [[100, 7.5, 'QAR']], [[99.99, 15, 'QAR']],
    [[33.33, 33.33, 'PKR']], [[1000, 10, 'JPY']], [[1.234, 12.5, 'KWD']],
    [[0, 20, 'QAR']], [[100, 0, 'QAR']], [[100, 100, 'QAR']],
]);

it('never lets a fee exceed the amount or go negative', function () {
    expect(Money::split(1000, 150)['fee'])->toBe(1000)
        ->and(Money::split(1000, 150)['remainder'])->toBe(0)
        ->and(Money::split(-500, 20)['fee'])->toBe(0)
        ->and(Money::percentOf(1000, -5))->toBe(0);
});
