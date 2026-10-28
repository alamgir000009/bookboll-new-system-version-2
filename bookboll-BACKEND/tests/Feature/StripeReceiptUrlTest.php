<?php

/**
 * A booking's Stripe receipt arrives with the webhook, not an hour later.
 *
 * fulfillPaidCheckoutSession() resolves the charge's hosted receipt and stamps
 * it on every payment row the charge settled — deposit, balance or full — so
 * Payments → Transactions can open it as soon as the money lands. The hourly
 * backfill remains the safety net for anything this misses.
 */

use App\Models\Payment;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function receiptPayment(string $chargeId, array $extra = []): Payment
{
    return Payment::create(array_merge([
        'payment_id' => 'PAY_' . uniqid(), 'user_id' => test()->user->id, 'booking_id' => null,
        'source_type' => 'booking', 'type' => 'deposit', 'method' => 'online', 'status' => 'paid',
        'amount' => 50, 'currency' => 'QAR', 'recipient' => '@bookboll', 'charge_id' => $chargeId,
    ], $extra));
}

function stamp(string $chargeId, ?string $url): int
{
    $service = app(StripeService::class);
    $method  = new ReflectionMethod($service, 'stampReceiptUrl');

    return $method->invoke($service, $chargeId, $url);
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('stamps the receipt on every payment the charge settled, and nothing else', function () {
    $deposit = receiptPayment('ch_abc', ['type' => 'deposit']);
    $balance = receiptPayment('ch_abc', ['type' => 'full', 'amount' => 150]);
    $other   = receiptPayment('ch_other');
    $kept    = receiptPayment('ch_abc', ['stripe_receipt_url' => 'https://pay.stripe.com/receipts/old']);

    $url = 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xTm9';

    expect(stamp('ch_abc', $url))->toBe(2)
        ->and($deposit->fresh()->stripe_receipt_url)->toBe($url)
        ->and($balance->fresh()->stripe_receipt_url)->toBe($url)
        ->and($other->fresh()->stripe_receipt_url)->toBeNull()
        ->and($kept->fresh()->stripe_receipt_url)->toBe('https://pay.stripe.com/receipts/old');
});

it('writes nothing without a receipt', function () {
    $payment = receiptPayment('ch_abc');

    expect(stamp('ch_abc', null))->toBe(0)
        ->and(stamp('', 'https://pay.stripe.com/receipts/x'))->toBe(0)
        ->and($payment->fresh()->stripe_receipt_url)->toBeNull();
});

it('only ever stores a Stripe https link', function () {
    $service = app(StripeService::class);
    $method  = new ReflectionMethod($service, 'receiptUrlForCharge');

    $charge = (object) ['receipt_url' => 'https://pay.stripe.com/receipts/payment/abc'];
    expect($method->invoke($service, $charge, 'ch_abc'))->toBe('https://pay.stripe.com/receipts/payment/abc');

    $tampered = (object) ['receipt_url' => 'https://evil.example/receipt'];
    expect($method->invoke($service, $tampered, 'ch_abc'))->toBeNull();
});
