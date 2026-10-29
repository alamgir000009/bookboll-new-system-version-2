<?php

/**
 * A rewards discount on a VENUE booking is Bookboll's money, end to end.
 *
 * The checkout quote already caps a reward at Bookboll's commission. These
 * tests pin down what happens after that cap, on the plain court-booking path:
 *
 *   - deposit bookings take the reward off the deposit (Bookboll's share) and
 *     leave the on-arrival remainder — the venue's money — untouched;
 *   - settling that deposit spends the points, exactly as a full payment does;
 *   - a full online payment pays the venue on the court price, with the reward
 *     funded out of Bookboll's fee, so the payout is identical with or without
 *     points.
 */

use App\Models\Booking;
use App\Models\Listing;
use App\Models\LoyaltyCheckoutQuote;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyProgram;
use App\Models\Payment;
use App\Models\ProviderWallet;
use App\Models\User;
use App\Models\Venue;
use App\Services\BookingService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const LVF_QA = 179;

beforeEach(function () {
    \Nnjeim\World\Models\Country::firstOrCreate(['id' => LVF_QA], [
        'name' => 'Qatar', 'iso2' => 'QA', 'iso3' => 'QAT',
        'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);
    LoyaltyProgram::create([
        'country_id' => LVF_QA, 'name' => 'Rewards', 'earn_rate' => 1, 'redeem_rate' => 100,
        'redeem_value' => 1, 'min_redeem' => 100, 'max_redeem_pct' => 100,
        'expiry_months' => 12, 'is_active' => true,
    ]);

    $this->owner = User::factory()->create(['country_id' => LVF_QA]);
    $this->venue = Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE, 'owner_user_id' => $this->owner->id,
        'maps_url' => 'https://maps.google.com/?q=0,0', 'country_id' => LVF_QA,
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    // QAR 500 court, 20% commission, deposit model where the deposit is that 20%.
    $this->listing = Listing::create([
        'name' => 'Court', 'venue_id' => $this->venue->id, 'status' => 'active',
        'base_price' => 500, 'capacity' => 10, 'slot_duration' => 60,
        'commission_rate' => 20, 'max_bookings_per_slot' => 1,
        'payment_options' => ['deposit' => ['percentage' => 20], 'online_payment_enabled' => true],
    ])->fresh('venue');

    $this->customer = User::factory()->create(['country_id' => LVF_QA, 'uuid' => (string) Str::uuid()]);
    LoyaltyPoint::create(['user_id' => $this->customer->id, 'points' => 100000, 'type' => 'credit', 'country_id' => LVF_QA, 'reason' => 'seed']);
});

/** One court booking through the customer checkout's per-slot creator. */
function lvfBooking(string $method, float $reward, ?string $quoteId = null): Booking
{
    $form = [
        'venue_id' => test()->venue->id, 'listing_id' => test()->listing->id,
        'payment_method' => $method, 'quantity' => 1, 'add_ons' => [],
        'loyalty_quote_id' => $quoteId,
    ];
    $booking = app(BookingService::class)->createSingleBooking(
        test()->customer, $form, now()->addDays(3)->format('Y-m-d'), '10:00', '11:00',
        500.0, $reward, 0.0, null, null, null, test()->listing, new Request(), $reward
    );
    expect($booking)->not->toBeNull();

    return $booking->fresh();
}

it('takes a deposit-booking reward off the deposit, never off what the venue collects on arrival', function () {
    $booking = lvfBooking('deposit_remaining_on_arrival', 50);

    $deposit   = Payment::where('booking_id', $booking->id)->where('type', 'deposit')->first();
    $remaining = Payment::where('booking_id', $booking->id)->where('type', 'remaining')->first();

    expect((float) $booking->total_price)->toBe(450.0)          // customer's total
        ->and((float) $booking->loyalty_discount)->toBe(50.0)
        ->and((float) $deposit->amount)->toBe(50.0)                // 20% of 500 = 100, minus the 50 reward
        ->and((float) $remaining->amount)->toBe(400.0)             // the venue still collects 400
        ->and((float) ($booking->payment_breakdown['deposit_amount'] ?? -1))->toBe(50.0)
        ->and((float) ($booking->payment_breakdown['remaining_amount'] ?? -1))->toBe(400.0);
});

it('leaves a deposit booking without a reward exactly as before', function () {
    $booking = lvfBooking('deposit_remaining_on_arrival', 0);

    expect((float) Payment::where('booking_id', $booking->id)->where('type', 'deposit')->value('amount'))->toBe(100.0)
        ->and((float) Payment::where('booking_id', $booking->id)->where('type', 'remaining')->value('amount'))->toBe(400.0)
        ->and($booking->loyalty_discount)->toBeNull();
});

it('can take the whole deposit as a reward and still owes the venue its remainder', function () {
    $booking = lvfBooking('deposit_remaining_on_arrival', 100);

    expect((float) Payment::where('booking_id', $booking->id)->where('type', 'deposit')->value('amount'))->toBe(0.0)
        ->and((float) Payment::where('booking_id', $booking->id)->where('type', 'remaining')->value('amount'))->toBe(400.0)
        ->and((float) $booking->total_price)->toBe(400.0);
});

it('spends the points when the deposit is settled', function () {
    $quote = LoyaltyCheckoutQuote::create([
        'quote_id' => 'lq_'.uniqid(), 'user_id' => $this->customer->id, 'country_id' => LVF_QA,
        'currency' => 'QAR', 'kind' => 'listing', 'points' => 5000, 'discount_minor' => 5000,
        'order_total_minor' => 50000, 'due_now_minor' => 10000, 'commission_minor' => 10000,
        'inputs_hash' => 'hash', 'expires_at' => now()->addMinutes(10), 'reserved_at' => now(),
    ]);
    $booking = lvfBooking('deposit_remaining_on_arrival', 50, $quote->quote_id);

    $service = app(StripeService::class);
    $apply   = new ReflectionMethod($service, 'applyPaymentToBooking');
    $apply->invoke($service, $booking->id, $this->customer->id, 'deposit', 'ch_test', 'pi_test');
    $apply->invoke($service, $booking->id, $this->customer->id, 'deposit', 'ch_test', 'pi_test');   // duplicate webhook

    expect($quote->fresh()->consumed_at)->not->toBeNull()
        ->and((int) LoyaltyPoint::where('user_id', $this->customer->id)->where('type', 'debit')->sum('points'))->toBe(5000)
        ->and(LoyaltyPoint::where('user_id', $this->customer->id)->where('type', 'debit')->count())->toBe(1)
        ->and(Payment::where('booking_id', $booking->id)->where('type', 'deposit')->value('status'))->toBe('paid');
});

it('pays the venue the same for a full online payment whether or not points were used', function () {
    ProviderWallet::create([
        'owner_user_id' => $this->owner->id, 'setup_method' => 'stripe', 'setup_status' => 'active',
        'payout_paused' => false, 'country_id' => LVF_QA, 'purpose' => 'bookings', 'currency' => 'QAR',
    ]);

    $earning = function (float $reward) {
        $booking = lvfBooking('online_payment', $reward);
        $service = app(StripeService::class);
        $apply   = new ReflectionMethod($service, 'applyPaymentToBooking');
        $apply->invoke($service, $booking->id, $this->customer->id, 'full', 'ch_'.uniqid(), 'pi_'.uniqid());

        return (float) Payment::where('booking_id', $booking->id)->where('type', 'booking_earning')->value('amount');
    };

    expect($earning(0))->toBe(400.0)     // 500 less the 20% fee
        ->and($earning(100))->toBe(400.0);   // Stripe collected 400, reward 100 — venue share unchanged
});
