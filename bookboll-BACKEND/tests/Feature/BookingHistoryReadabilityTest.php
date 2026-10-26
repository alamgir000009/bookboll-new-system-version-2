<?php

/**
 * The history your staff actually read.
 *
 * A booking's history is the first thing anyone opens when a customer disputes
 * something, and it was answering none of their questions:
 *
 *   The cancellation line was built by a sprintf handed SIX arguments into a
 *   format string with THREE placeholders. The reason a booking was cancelled,
 *   the payment status at the time, and the payment count were all computed and
 *   then silently dropped — so the one field a manager needs, "why?", never
 *   reached the page. What it did print, "cancelled from pending to cancelled",
 *   says the new status twice on a row that is already a cancellation.
 *
 *   Creation wrote TWO rows a second apart — "Booking created by X" and
 *   "Booking created with deposit payment pending." — both tagged
 *   booking_created, neither carrying an amount. It read like a glitch.
 *
 *   Money appeared as bare numbers, or not at all, on a platform now running in
 *   several currencies; and several lines led with internal row ids
 *   ("Payment #418", "user #4812") that no reader can resolve.
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

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);
    $bronze = (int) LoyaltyTier::where('slug', 'bronze')->value('id');

    Role::findOrCreate('customer', 'web');
    Role::findOrCreate('staff', 'web');

    ['listings' => $listings, 'venue' => $venue, 'date' => $date] =
        courtPool(4, ['court' => ['max' => 2]]);

    $this->listing = $listings['court'];
    $this->venue   = $venue;
    $this->date    = $date;

    $this->customer = User::factory()->create([
        'email' => 'client@test.local', 'name' => 'Abdullah Abazll',
        'status' => User::ACTIVE, 'loyalty_tier_id' => $bronze,
    ]);
    $this->customer->assignRole('customer');

    $this->staff = User::factory()->create([
        'email' => 'desk@test.local', 'name' => 'Front Desk',
        'status' => User::ACTIVE, 'loyalty_tier_id' => $bronze,
    ]);
    $this->staff->assignRole('staff');
});

function aBooking(string $paymentStatus = BookingPaymentStatus::PENDING): Booking
{
    return Booking::create([
        'user_id'        => test()->customer->id,
        'venue_id'       => test()->venue->id,
        'listing_id'     => test()->listing->id,
        'date'           => test()->date,
        'start_time'     => '20:00',
        'end_time'       => '21:00',
        'status'         => BookingStatus::PENDING,
        'payment_status' => $paymentStatus,
        'payment_method' => BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
        'total_price'    => 125,
        'subtotal'       => 125,
        'currency'       => 'QAR',
    ]);
}

/** Every history line on a booking, newest last. */
function historyLines(Booking $booking): array
{
    return $booking->history()->orderBy('id')->pluck('description')->all();
}

// ── Cancellation ─────────────────────────────────────────────────────────────

it('records why a booking was cancelled', function () {
    // The defect: the reason was passed to sprintf and never printed.
    $booking = aBooking();

    $this->actingAs($this->customer)->postJson('/api/customer/bookings/cancel', [
        'booking_id'    => $booking->id,
        'cancel_reason' => 'Customer could not make it',
    ])->assertSuccessful();

    $cancelled = $booking->history()->where('action', 'booking_cancelled')->first()->description;

    expect($cancelled)->toContain('Customer could not make it')
        ->toContain('Abdullah Abazll');
});

it('stops saying the new status twice', function () {
    $booking = aBooking();

    $this->actingAs($this->customer)->postJson('/api/customer/bookings/cancel', [
        'booking_id' => $booking->id,
    ])->assertSuccessful();

    expect($booking->history()->where('action', 'booking_cancelled')->first()->description)
        ->not->toContain('from pending to cancelled');
});

it('says in plain words whether any money had been taken', function () {
    $booking = aBooking();

    $this->actingAs($this->customer)->postJson('/api/customer/bookings/cancel', [
        'booking_id' => $booking->id,
    ])->assertSuccessful();

    expect($booking->history()->where('action', 'booking_cancelled')->first()->description)
        ->toContain('nothing had been paid')
        // Nothing was taken, so nobody should be sent chasing a refund.
        ->not->toContain('refund may be owed');
});

it('flags a refund decision when a paid booking is cancelled', function () {
    $booking = aBooking(BookingPaymentStatus::DEPOSIT_PAID);

    processBookingCancellation($booking, $this->staff->id, 'Venue double-booked');

    expect($booking->history()->where('action', 'booking_cancelled')->first()->description)
        ->toContain('the deposit had been paid')
        ->toContain('refund may be owed')
        ->toContain('Venue double-booked');
});

// ── Creation ─────────────────────────────────────────────────────────────────

it('writes one creation line, not two', function () {
    $this->actingAs($this->customer)->postJson('/api/customer/bookings/create', [
        'venue_id'       => $this->venue->id,
        'listing_id'     => $this->listing->id,
        'slots'          => [['date' => $this->date, 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1]],
        'payment_method' => 'cash_on_arrival',
    ])->assertSuccessful();

    $booking = Booking::where('user_id', $this->customer->id)->latest('id')->first();

    expect($booking->history()->where('action', 'booking_created')->count())->toBe(1);
});

it('puts the amount and the payment plan on the creation line', function () {
    $this->actingAs($this->customer)->postJson('/api/customer/bookings/create', [
        'venue_id'       => $this->venue->id,
        'listing_id'     => $this->listing->id,
        'slots'          => [['date' => $this->date, 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1]],
        'payment_method' => 'cash_on_arrival',
    ])->assertSuccessful();

    $booking = Booking::where('user_id', $this->customer->id)->latest('id')->first();
    $created = $booking->history()->where('action', 'booking_created')->first()->description;

    expect($created)->toContain('Abdullah Abazll')
        // Money must carry its currency — a bare number is not an amount.
        ->toMatch('/[A-Z]{3}\s[\d,]+\.\d{2}/');
});

// ── Payments ─────────────────────────────────────────────────────────────────

it('names the amount and the reference when Stripe settles a deposit', function () {
    $booking = aBooking();

    $payment = Payment::create([
        'payment_id' => generatePaymentId($booking->id, 'deposit'),
        'booking_id' => $booking->id,
        'user_id'    => $this->customer->id,
        'amount'     => 25,
        'type'       => PaymentType::DEPOSIT,
        'status'     => PaymentStatus::PENDING,
        'method'     => PaymentMethod::ONLINE,
        'notes'      => 'Deposit payment for booking pending',
    ]);

    $service = app(StripeService::class);
    $method  = new ReflectionMethod($service, 'settleBookingsForCharge');
    $method->setAccessible(true);
    $method->invoke($service, [$booking->id], $this->customer->id, 'deposit', 'ch_abc123', 'pi_abc123');

    $line = $booking->history()->where('action', 'payment_received')->first()->description;

    expect($line)->toContain('25.00')
        ->toContain($payment->payment_id)
        ->toContain('ch_abc123')
        // The internal row id is not an identifier anyone can look up.
        ->not->toContain("Payment #{$payment->id} ");
});

it('never shows a raw user id where a name belongs', function () {
    $booking = aBooking();

    processBookingCancellation($booking, $this->staff->id, 'Testing');

    foreach (historyLines($booking) as $line) {
        expect($line)->not->toMatch('/user #\d+/');
    }
});
