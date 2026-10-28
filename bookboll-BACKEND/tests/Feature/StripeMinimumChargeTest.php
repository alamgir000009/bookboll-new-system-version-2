<?php

/**
 * Stripe's floor on what it will charge.
 *
 * Stripe refuses any total converting to under about US$0.50. This never
 * surfaced while PKR bookings were being sent as QAR — QAR 50 is roughly
 * US$13 — and appeared the moment the currency was right: ₨50 is about
 * US$0.26, and Stripe answered the customer with a sentence about cent
 * conversions. The floor is now checked first, so the refusal is ours.
 */

use App\Support\StripeMinimumCharge;

it('knows the floor for a configured currency', function () {
    expect(StripeMinimumCharge::floorFor('PKR'))->toBe(200.00)
        ->and(StripeMinimumCharge::floorFor('pkr'))->toBe(200.00)
        ->and(StripeMinimumCharge::floorFor(' QAR '))->toBe(2.00);
});

it('refuses an amount under the floor', function () {
    // The exact case from the Stripe error.
    expect(StripeMinimumCharge::isBelowFloor(50.00, 'PKR'))->toBeTrue()
        ->and(StripeMinimumCharge::isBelowFloor(199.99, 'PKR'))->toBeTrue();
});

it('accepts an amount at or over the floor', function () {
    expect(StripeMinimumCharge::isBelowFloor(200.00, 'PKR'))->toBeFalse()
        ->and(StripeMinimumCharge::isBelowFloor(5000.00, 'PKR'))->toBeFalse()
        ->and(StripeMinimumCharge::isBelowFloor(50.00, 'QAR'))->toBeFalse();
});

it('leaves an unconfigured currency to Stripe rather than guessing', function () {
    // Blocking a payment Stripe would have taken is the worse mistake.
    expect(StripeMinimumCharge::floorFor('XYZ'))->toBeNull()
        ->and(StripeMinimumCharge::isBelowFloor(0.01, 'XYZ'))->toBeFalse()
        ->and(StripeMinimumCharge::isBelowFloor(0.01, null))->toBeFalse()
        ->and(StripeMinimumCharge::isBelowFloor(0.01, ''))->toBeFalse();
});

it('explains the shortfall in the customer’s own currency', function () {
    $message = StripeMinimumCharge::message(50.00, 'PKR');

    expect($message)->toContain('PKR 50.00')
        ->toContain('PKR 200.00')
        ->toContain('pay at the venue')
        // Never Stripe's own wording about cent conversions.
        ->not->toContain('cents')
        ->not->toContain('$');
});

it('configures a floor for every currency the platform charges in', function () {
    foreach (['QAR', 'AED', 'PKR'] as $code) {
        expect(StripeMinimumCharge::floorFor($code))->not->toBeNull("no floor configured for {$code}");
    }
});

it('checks the floor before building either checkout payload', function () {
    $source = file_get_contents(app_path('Services/PaymentService.php'));

    // Both Stripe checkouts must refuse first, not let Stripe refuse later.
    expect(substr_count($source, 'StripeMinimumCharge::isBelowFloor'))->toBe(2);

    $customerCheckout = strpos($source, 'StripeMinimumCharge::isBelowFloor((float) $paymentAmount');
    $payload = strpos($source, '$sessionData = [');

    expect($customerCheckout)->toBeLessThan($payload);
});
