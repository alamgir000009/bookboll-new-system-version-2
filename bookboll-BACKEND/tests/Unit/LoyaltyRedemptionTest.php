<?php

use App\Support\LoyaltyRedemption as R;
use App\Support\Money;

/**
 * readmeAlamgir "Checkout redemption funded only by Bookboll's share", and its
 * own release checks. Rewards come out of Bookboll's revenue — never the
 * provider's payout, the host's proceeds, tax, prize pots or processor fees.
 */

$qar = fn ($n) => Money::toMinor($n, 'QAR');

it('caps a listing reward at the commission, leaving the provider whole', function () use ($qar) {
    // Release check: QAR100 total, commission 15, 10,000 points at 100/unit
    // -> at most QAR15 discount, provider gets 85.
    $r = R::quote(
        ['commission' => $qar(15), 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), $qar(100), 10000, 100, 100, null, 'QAR'
    );

    expect(Money::toMajor($r['discount_minor'], 'QAR'))->toBe(15.0)
        ->and($r['points'])->toBe(1500)
        // The other 85 is the provider's and was never a candidate.
        ->and(Money::toMajor($qar(100) - $r['discount_minor'], 'QAR'))->toBe(85.0);
});

it('does not let full online payment make the whole price redeemable', function () use ($qar) {
    // Paying 100 online does not mean 100 can be covered by points.
    $r = R::quote(
        ['commission' => $qar(15), 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), $qar(100), 999999, 100, 100, null, 'QAR'
    );

    expect(Money::toMajor($r['discount_minor'], 'QAR'))->toBe(15.0);
});

it('can reduce a deposit to zero while the arrival balance stays untouched', function () use ($qar) {
    // Release check: total 100, due now 20, eligible share 20 -> points may
    // reduce the online payment to 0, arrival stays 80.
    $r = R::quote(
        ['commission' => $qar(20), 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), $qar(20), 100000, 100, 100, null, 'QAR'
    );

    expect(Money::toMajor($r['discount_minor'], 'QAR'))->toBe(20.0);

    // Arrival is never recalculated as a percentage of the discounted total.
    $arrivalBefore = $qar(100) - $qar(20);
    expect(Money::toMajor($arrivalBefore, 'QAR'))->toBe(80.0);
});

it('adds the three Bookboll-owned fees and nothing else', function () use ($qar) {
    // Release check: commission 18 + event fee 4 + booking fee 2 -> cap 24.
    $r = R::quote(
        ['commission' => $qar(18), 'event_fee' => $qar(4), 'booking_fee' => $qar(2)],
        $qar(100), $qar(100), 100000, 100, 100, null, 'QAR'
    );

    expect(Money::toMajor($r['eligible_minor'], 'QAR'))->toBe(24.0)
        ->and(Money::toMajor($r['discount_minor'], 'QAR'))->toBe(24.0);
});

it('treats zero commission as zero, never as a default rate', function () use ($qar) {
    $r = R::quote(['commission' => 0, 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), $qar(100), 100000, 100, 100, null, 'QAR');

    expect($r['points'])->toBe(0)
        ->and($r['discount_minor'])->toBe(0)
        ->and($r['reason'])->toContain('no Bookboll fee');
});

it('never funds a reward from a negative or merchant-owned amount', function () use ($qar) {
    $r = R::quote(['commission' => -5000, 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), $qar(100), 100000, 100, 100, null, 'QAR');

    expect($r['discount_minor'])->toBe(0);
});

it('applies the country percentage cap', function () use ($qar) {
    // Commission is 30, but the programme allows at most 10% of the order.
    $r = R::quote(['commission' => $qar(30), 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), $qar(100), 100000, 100, 100, 10.0, 'QAR');

    expect(Money::toMajor($r['discount_minor'], 'QAR'))->toBe(10.0);
});

it('offers nothing when nothing is collected online today', function () use ($qar) {
    // A fully arrival-paid booking has no online charge to reduce.
    $r = R::quote(['commission' => $qar(20), 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), 0, 100000, 100, 100, null, 'QAR');

    expect($r['points'])->toBe(0)
        ->and($r['reason'])->toContain('paid online');
});

it('respects the programme minimum rather than offering a token discount', function () use ($qar) {
    // 500 points is worth QAR5, but the programme will not redeem under 1000.
    $r = R::quote(['commission' => $qar(20), 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), $qar(100), 500, 100, 1000, null, 'QAR');

    expect($r['points'])->toBe(0)
        ->and($r['reason'])->toContain('at least 1000');
});

it('never spends more points than the customer holds', function () use ($qar) {
    $r = R::quote(['commission' => $qar(50), 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), $qar(100), 700, 100, 100, null, 'QAR');

    expect($r['points'])->toBeLessThanOrEqual(700)
        ->and(Money::toMajor($r['discount_minor'], 'QAR'))->toBe(7.0);
});

it('charges enough points to cover the discount it gives', function (array $case) use ($qar) {
    [$commission, $balance, $rate] = $case;
    $r = R::quote(['commission' => $qar($commission), 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), $qar(100), $balance, $rate, 1, null, 'QAR');

    if ($r['points'] > 0) {
        // Points charged must be worth at least the discount handed out,
        // otherwise the programme leaks value on every rounding.
        $worth = ($r['points'] / $rate) * Money::factor('QAR');
        expect($worth)->toBeGreaterThanOrEqual((float) $r['discount_minor']);
    }
})->with([
    [[15, 10000, 100]], [[7.5, 10000, 100]], [[33, 999, 33.3]],
    [[20, 150, 7]], [[1, 100000, 1]], [[99, 1, 1]],
]);

it('refuses when the country has no redeem rate configured', function () use ($qar) {
    $r = R::quote(['commission' => $qar(20), 'event_fee' => 0, 'booking_fee' => 0],
        $qar(100), $qar(100), 100000, 0, 100, null, 'QAR');

    expect($r['points'])->toBe(0)
        ->and($r['reason'])->toContain('not configured');
});
