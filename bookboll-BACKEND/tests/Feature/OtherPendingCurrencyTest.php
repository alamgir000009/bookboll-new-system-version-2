<?php

/**
 * The "Other pending payments" list names the currency each amount is in.
 *
 * The mapper used to send a hardcoded empty currency, so the dashboard applied
 * its own AED fallback to every row — a Lahore booking in PKR and a Doha one in
 * QAR both read as dirhams in the same list. Resolution order: the payment's own
 * currency, the booking's stamped one, then the venue's (override → country).
 * Nothing resolvable means empty, never an invented code.
 */

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function pendVenue(string $override = ''): Venue
{
    return Venue::create([
        'name' => 'V'.uniqid(), 'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ] + ($override ? ['currency_override' => $override] : []));
}

function pendBooking(Venue $venue, array $attrs = []): Booking
{
    return Booking::create($attrs + [
        'user_id' => User::factory()->create()->id, 'venue_id' => $venue->id,
        'date' => now()->addDays(2)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'status' => 'confirmed',
    ]);
}

function pendPayment(Booking $b, array $attrs = []): Payment
{
    return Payment::create($attrs + [
        'payment_id' => 'PAY_'.uniqid(), 'user_id' => $b->user_id, 'booking_id' => $b->id,
        'type' => 'full', 'method' => 'online', 'status' => 'pending',
        'amount' => 90, 'recipient' => '@bookboll',
    ]);
}

/** The mapper's rows, keyed by payment id. */
function otherPending(Booking $current): array
{
    $m = new ReflectionMethod(BookingService::class, 'mapDashboardOtherPending');
    $m->setAccessible(true);

    return collect($m->invoke(app(BookingService::class), $current))->keyBy('id')->all();
}

it('names each pending amount in the currency it was actually charged in', function () {
    $current = pendBooking(pendVenue('QAR'));

    $pkr = pendPayment(pendBooking(pendVenue('PKR')));          // from the venue override
    $qar = pendPayment(pendBooking(pendVenue('QAR'), ['currency' => 'QAR']));  // stamped on the booking
    $own = pendPayment(pendBooking(pendVenue('PKR')), ['currency' => 'USD']);  // the payment's own wins

    $rows = otherPending($current);

    expect($rows[(string) $pkr->id]['currency'])->toBe('PKR')
        ->and($rows[(string) $qar->id]['currency'])->toBe('QAR')
        ->and($rows[(string) $own->id]['currency'])->toBe('USD');
});

it('sends an empty currency rather than inventing one', function () {
    $current = pendBooking(pendVenue('QAR'));
    $b       = pendBooking(pendVenue());   // no override, no country
    // Booking::booted() stamps a currency on every new row (last resort: the
    // platform default). Clear it to reach the case this mapper must not paper
    // over — an older row written before that stamp existed.
    DB::table('bookings')->where('id', $b->id)->update(['currency' => null]);
    $unknown = pendPayment($b);

    expect(otherPending($current)[(string) $unknown->id]['currency'])->toBe('');
});

it('prefers the stored booking currency over re-deriving it from the venue', function () {
    // A venue that changed currency after the fact must not relabel money
    // already taken — the stamped value wins.
    $venue   = pendVenue('QAR');
    $current = pendBooking($venue);
    $old     = pendBooking($venue, ['currency' => 'PKR']);
    $venue->update(['currency_override' => 'USD']);
    $payment = pendPayment($old);

    expect(otherPending($current)[(string) $payment->id]['currency'])->toBe('PKR');
});

it('resolves each venue once, however many of its payments are pending', function () {
    $current = pendBooking(pendVenue('QAR'));
    $venue   = pendVenue('PKR');
    foreach (range(1, 6) as $i) {
        pendPayment(pendBooking($venue));
    }

    DB::enableQueryLog();
    $rows = otherPending($current);
    $venueQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `venues`'))->count();
    DB::disableQueryLog();

    expect(collect($rows)->pluck('currency')->unique()->all())->toBe(['PKR'])
        ->and($venueQueries)->toBeLessThanOrEqual(1);
});
