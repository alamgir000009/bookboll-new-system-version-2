<?php

/**
 * What currency a Stripe checkout charges in.
 *
 * Two separate faults produced the same symptom. A pay link resolved from
 * venue.currency_override alone and fell through to a hard-coded 'QAR'; the
 * customer and admin checkouts in PaymentService did not resolve anything at
 * all — they passed the string literal 'qar' straight into price_data.
 *
 * So a Pakistani booking stamped PKR was handed to Stripe as QAR: the right
 * number in a currency worth roughly seventy-five times more. The booking row
 * knew its currency the whole time — Booking::currencyForVenue() stamps it at
 * creation — and nothing asked.
 *
 * The guard below is deliberately a source check. A literal currency cannot be
 * caught by exercising the code without standing up Stripe, and this is the
 * second time one has reached production.
 */

use App\Models\Booking;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Every `'currency' => '...'` written as a literal rather than resolved. */
function literalCurrencyAssignments(string $path): array
{
    $source = file_get_contents($path);

    preg_match_all(
        "/'currency'\s*=>\s*'([a-z]{3})'/i",
        $source,
        $matches,
        PREG_SET_ORDER
    );

    return array_map(fn (array $m) => $m[0], $matches);
}

it('never hands Stripe a hard-coded currency', function (string $file) {
    expect(literalCurrencyAssignments(app_path($file)))->toBe([]);
})->with([
    'Services/PaymentService.php',
    'Services/BookingPayLinkService.php',
    'Services/StripeService.php',
    'Services/CustomerGameService.php',
]);

it('loads the currency column on the bookings a checkout charges for', function () {
    // The customer checkout selects a narrow column list; omitting `currency`
    // there is how the resolved value silently becomes the fallback.
    $source = file_get_contents(app_path('Services/PaymentService.php'));

    preg_match('/\$allBookings = Booking::whereIn\(.*?\)->get\(\[(.*?)\]\)/s', $source, $m);

    expect($m[1] ?? '')->toContain("'currency'");
});

it('stamps a booking with its venue currency and keeps it', function () {
    $venue = Venue::create([
        'name' => 'Lahore venue', 'status' => Venue::ACTIVE,
        'owner_user_id' => User::factory()->create()->id,
        'currency_override' => 'PKR',
        'max_bookings_per_slot' => 1, 'min_advance_booking' => 0,
    ]);

    $listing = Listing::create([
        'name' => 'Lahore listing', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 25, 'capacity' => 10, 'slot_duration' => 60,
        'commission_rate' => 15, 'payment_options' => [], 'max_bookings_per_slot' => 1,
    ]);

    $booking = Booking::create([
        'user_id' => User::factory()->create()->id,
        'venue_id' => $venue->id,
        'listing_id' => $listing->id,
        'date' => now()->addDay()->toDateString(),
        'start_time' => '17:00:00', 'end_time' => '18:00:00',
        'subtotal' => 50, 'total_price' => 50, 'status' => 'pending',
    ])->refresh();

    expect($booking->currency)->toBe('PKR')
        ->and(strtolower($booking->currency))->toBe('pkr');
});
