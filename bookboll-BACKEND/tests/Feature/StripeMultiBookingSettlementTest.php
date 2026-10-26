<?php

/**
 * "I paid the deposit but it still says pending."
 *
 * A customer booked two consecutive slots in one checkout. Stripe took QAR 45
 * (25 + 20) and reported it Succeeded. Both bookings still read `pending`, and
 * AutoCancelUnpaidDepositBookings then cancelled them — the money was kept and
 * nothing was booked.
 *
 * The cause was one `throw` inside the fulfilment transaction. A checkout
 * routinely covers several bookings, and the loop refused the WHOLE charge the
 * moment any one of them was cancelled or missing. Because the throw unwound
 * the transaction, the live sibling was never paid either, and
 * `stripe_sessions.processed` stayed false — so the auto-cancel job's own
 * safety net (it asks Stripe before cancelling, then fulfils) could not settle
 * anything, and cancelled the survivor as unpaid.
 *
 * Refusing to pay INTO a cancelled booking is right. Refusing to pay its
 * siblings is not.
 *
 * Exercised through settleBookingsForCharge(), which is the whole of that
 * decision and touches no Stripe API — the charge and intent ids are already
 * resolved by the time it runs.
 */

use App\Models\Booking;
use App\Models\LoyaltyTier;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeService;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const CHARGE_ID = 'ch_test_3UUSRDEbdBUtsAfX1';
const INTENT_ID = 'pi_3UUSRDEbdBUtsAfX11wjN60a';

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);
    $bronze = (int) LoyaltyTier::where('slug', 'bronze')->value('id');

    Role::findOrCreate('customer', 'web');

    ['listings' => $listings, 'venue' => $venue, 'date' => $date] =
        courtPool(4, ['court' => ['max' => 2]]);

    $this->listing = $listings['court'];
    $this->venue   = $venue;
    $this->date    = $date;

    $this->customer = User::factory()->create([
        'email' => 'payer@test.local', 'status' => User::ACTIVE, 'loyalty_tier_id' => $bronze,
    ]);
    $this->customer->assignRole('customer');
});

/** One booking awaiting its online deposit, with the pending deposit row. */
function depositBooking(string $start, string $end, float $total, float $deposit): Booking
{
    $booking = Booking::create([
        'user_id'        => test()->customer->id,
        'venue_id'       => test()->venue->id,
        'listing_id'     => test()->listing->id,
        'date'           => test()->date,
        'start_time'     => $start,
        'end_time'       => $end,
        'status'         => BookingStatus::PENDING,
        'payment_status' => BookingPaymentStatus::PENDING,
        'payment_method' => BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
        'total_price'    => $total,
        'subtotal'       => $total,
    ]);

    Payment::create([
        'payment_id' => generatePaymentId($booking->id, 'deposit'),
        'booking_id' => $booking->id,
        'user_id'    => test()->customer->id,
        'amount'     => $deposit,
        'type'       => PaymentType::DEPOSIT,
        'status'     => PaymentStatus::PENDING,
        'method'     => PaymentMethod::ONLINE,
        'notes'      => 'Deposit payment for booking #' . $booking->booking_id . ' pending',
    ]);

    return $booking->fresh();
}

/** Run the settlement the webhook runs, without touching Stripe. */
function settle(array $bookingIds): array
{
    $service = app(StripeService::class);

    $method = new ReflectionMethod($service, 'settleBookingsForCharge');
    $method->setAccessible(true);

    return $method->invoke(
        $service,
        $bookingIds,
        test()->customer->id,
        'deposit',
        CHARGE_ID,
        INTENT_ID
    );
}

it('settles every booking the charge covered', function () {
    $first  = depositBooking('20:00', '21:00', 125, 25);
    $second = depositBooking('21:00', '22:00', 100, 20);

    [$settled, $unsettled] = settle([$first->id, $second->id]);

    expect($settled)->toBe([$first->id, $second->id])
        ->and($unsettled)->toBe([]);

    foreach ([$first, $second] as $booking) {
        expect($booking->fresh()->payment_status)->toBe(BookingPaymentStatus::DEPOSIT_PAID);
    }
});

it('still pays the live booking when its sibling was cancelled', function () {
    // The reported case exactly: one slot cancelled, one still live, one charge.
    $cancelled = depositBooking('20:00', '21:00', 125, 25);
    $live      = depositBooking('21:00', '22:00', 100, 20);
    $cancelled->update(['status' => BookingStatus::CANCELLED]);

    [$settled, $unsettled] = settle([$cancelled->id, $live->id]);

    expect($settled)->toBe([$live->id])
        ->and($live->fresh()->payment_status)->toBe(BookingPaymentStatus::DEPOSIT_PAID);

    // The cancelled one is reported, not silently dropped — it is somebody's money.
    expect($unsettled)->toHaveCount(1)
        ->and($unsettled[0]['booking_id'])->toBe($cancelled->id)
        ->and($unsettled[0]['reason'])->toBe('cancelled');
});

it('never pays into a cancelled booking', function () {
    $cancelled = depositBooking('20:00', '21:00', 125, 25);
    $cancelled->update(['status' => BookingStatus::CANCELLED]);

    settle([$cancelled->id]);

    expect($cancelled->fresh()->payment_status)->toBe(BookingPaymentStatus::PENDING)
        ->and(Payment::where('booking_id', $cancelled->id)->first()->status)
        ->toBe(PaymentStatus::PENDING);
});

it('reports a booking that has gone missing instead of aborting the charge', function () {
    $live = depositBooking('21:00', '22:00', 100, 20);

    [$settled, $unsettled] = settle([999999, $live->id]);

    expect($settled)->toBe([$live->id])
        ->and($unsettled[0]['reason'])->toBe('not_found');
});

it('writes the Stripe charge and intent onto the payment it settles', function () {
    // The other half of the complaint: a settled payment must carry its Stripe
    // identifiers, which is what every link and reconciliation screen reads.
    $booking = depositBooking('20:00', '21:00', 125, 25);

    settle([$booking->id]);

    $payment = Payment::where('booking_id', $booking->id)->first();

    expect($payment->status)->toBe(PaymentStatus::PAID)
        ->and($payment->charge_id)->toBe(CHARGE_ID)
        ->and($payment->payment_intent_id)->toBe(INTENT_ID);
});

it('leaves a history entry naming the charge', function () {
    $booking = depositBooking('20:00', '21:00', 125, 25);

    settle([$booking->id]);

    expect($booking->history()->where('action', 'payment_received')->first()->description)
        ->toContain(CHARGE_ID);
});
