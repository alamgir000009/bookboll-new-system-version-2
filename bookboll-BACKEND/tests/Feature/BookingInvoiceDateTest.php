<?php

/**
 * The booking invoice PDF shows the day the customer plays.
 *
 * `bookings.date` is nullable and `Carbon::parse(null)` returns NOW, so this
 * document used to print TODAY for a booking with no date — the single place a
 * customer was most likely to read it as "the date of my booking".
 */

use App\Models\Booking;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prints the booking date, and never today when the booking has none', function () {
    $user = User::factory()->create(['name' => 'Syazwan']);
    $venue = Venue::create([
        'name' => 'Abu Hamor', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'operator_type' => 'bookboll', 'owner_user_id' => null, 'business_id' => null,
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    $listing = Listing::create([
        'name' => 'Indoor Padel Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 175, 'capacity' => 4, 'slot_duration' => 2,
        'payment_options' => ['cash_on_arrival' => '0'], 'max_bookings_per_slot' => 1,
    ]);

    $playsOn = now()->addDays(3);

    foreach ([$playsOn->toDateString(), null] as $date) {
        $booking = Booking::create([
            'user_id' => $user->id, 'venue_id' => $venue->id, 'listing_id' => $listing->id,
            'booking_id' => 'BKN_X', 'date' => $date,
            'start_time' => '19:00', 'end_time' => '21:00',
            'subtotal' => 175, 'total_price' => 175, 'status' => 'confirmed',
        ]);
        $booking->load('user', 'venue', 'listing', 'payments', 'addOns');

        $html = view('pdfs.booking-invoice', ['booking' => $booking, 'invoiceNumber' => 'INV-1'])->render();

        if ($date) {
            expect($html)->toContain($playsOn->format('l, d F Y'));
        } else {
            expect($html)->toContain('Not set');
            expect($html)->not->toContain(now()->format('l, d F Y'));
        }
    }
});
