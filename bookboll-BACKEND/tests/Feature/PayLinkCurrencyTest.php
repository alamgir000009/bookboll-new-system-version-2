<?php

/**
 * What currency a pay link charges in.
 *
 * It read the venue's currency_override and nothing else, falling through to
 * config('services.stripe.currency') — a key that does not exist — and so to a
 * hard-coded 'QAR'. Every venue in the database relies on its country rather
 * than an override, so a Pakistani booking of PKR 50 was handed to Stripe as
 * QAR 50: the right number in a currency worth about seventy-five times more.
 *
 * The booking's own `currency` column is the authority. Booking::currencyForVenue()
 * resolves override -> country -> platform default and stamps it at creation,
 * precisely so what the customer was quoted cannot be re-derived differently later.
 */

use App\Models\Booking;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use App\Services\BookingPayLinkService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Reaches the protected resolver without going near Stripe. */
function payLinkCurrency(Booking $booking): string
{
    $service = new class(app(StripeService::class)) extends BookingPayLinkService
    {
        public function currencyOf(Booking $booking): string
        {
            return $this->currencyFor($booking);
        }
    };

    return $service->currencyOf($booking);
}

function bookingAt(array $venueAttributes, array $bookingAttributes = []): Booking
{
    $venue = Venue::create(array_merge([
        'name' => 'Lahore venue',
        'status' => Venue::ACTIVE,
        'owner_user_id' => User::factory()->create()->id,
        'max_bookings_per_slot' => 1,
        'min_advance_booking' => 0,
    ], $venueAttributes));

    $listing = Listing::create([
        'name' => 'Lahore listing', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 25, 'capacity' => 10, 'slot_duration' => 60,
        'commission_rate' => 15, 'payment_options' => [], 'max_bookings_per_slot' => 1,
    ]);

    return Booking::create(array_merge([
        'user_id' => User::factory()->create()->id,
        'venue_id' => $venue->id,
        'listing_id' => $listing->id,
        'date' => now()->addDay()->toDateString(),
        'start_time' => '17:00:00',
        'end_time' => '18:00:00',
        'subtotal' => 50,
        'total_price' => 50,
        'status' => 'pending',
    ], $bookingAttributes))->refresh();
}

it('charges in the currency stamped on the booking', function () {
    // The exact shape of the bug: no override anywhere, booking stamped PKR.
    $booking = bookingAt(['currency_override' => null], ['currency' => 'PKR']);

    expect($booking->venue->currency_override)->toBeNull()
        ->and(payLinkCurrency($booking))->toBe('PKR');
});

it('does not fall back to QAR for a venue without an override', function () {
    $booking = bookingAt(['currency_override' => null], ['currency' => 'PKR']);

    expect(payLinkCurrency($booking))->not->toBe('QAR');
});

it('honours an explicit venue override', function () {
    $booking = bookingAt(['currency_override' => 'AED'], ['currency' => 'AED']);

    expect(payLinkCurrency($booking))->toBe('AED');
});

it('prefers what the booking was quoted over what the venue says today', function () {
    // A venue that changes currency must not relabel money already taken.
    $booking = bookingAt(['currency_override' => 'AED'], ['currency' => 'PKR']);

    expect(payLinkCurrency($booking))->toBe('PKR');
});

it('falls back to the venue chain for a legacy booking with no currency', function () {
    $booking = bookingAt(['currency_override' => 'AED']);
    $booking->forceFill(['currency' => null])->save();

    expect(payLinkCurrency($booking->refresh()))->toBe('AED');
});

it('stamps the booking from the venue override at creation', function () {
    $booking = bookingAt(['currency_override' => 'PKR']);

    expect($booking->currency)->toBe('PKR');
});

it('no longer reads a stripe currency config that does not exist', function () {
    expect(config('services.stripe.currency'))->toBeNull();

    $source = file_get_contents(app_path('Services/BookingPayLinkService.php'));

    // Only the comment explaining the bug may mention it.
    expect(substr_count($source, "config('services.stripe.currency')"))->toBe(1);
});
