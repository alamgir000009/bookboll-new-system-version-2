<?php

/**
 * Booking notifications must show the day the customer PLAYS.
 *
 * A customer reported a booking notification showing the wrong day. The cause
 * was not a wrong column but a silent fallback: `bookings.date` is nullable and
 * `Carbon::parse(null)` returns NOW, so a booking without a date rendered as
 * today — indistinguishable from "the day you booked". These tests pin both
 * halves: the real date is shown when there is one, and today is never
 * substituted when there is not.
 */

use App\Models\Booking;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingCancelledUserNotification;
use App\Notifications\BookingConfirmedUserNotification;
use App\Notifications\BookingCreatedAdminNotification;
use App\Notifications\BookingCreatedUserNotification;
use App\Notifications\BookingUpdatedUserNotification;
use App\Support\BookingDate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Every customer-facing booking notification, built the way each one is dispatched. */
function bookingNotifications(Booking $booking): array
{
    return [
        'confirmed' => new BookingConfirmedUserNotification($booking),
        'cancelled' => new BookingCancelledUserNotification($booking),
        'created'   => new BookingCreatedUserNotification($booking),
        'admin'     => new BookingCreatedAdminNotification($booking),
        'updated'   => new BookingUpdatedUserNotification($booking, ['Time slot changed']),
    ];
}

/** Every line of a rendered mail, intro and outro together. */
function mailLines($notification, User $user): string
{
    $mail = $notification->toMail($user);

    return implode("\n", array_map(
        fn ($line) => (string) $line,
        array_merge($mail->introLines, $mail->outroLines)
    ));
}

function bookingFor(User $user, ?string $date): Booking
{
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

    $booking = Booking::create([
        'user_id' => $user->id, 'venue_id' => $venue->id, 'listing_id' => $listing->id,
        'booking_id' => 'BKN_2026_11_00008', 'date' => $date,
        'start_time' => '19:00', 'end_time' => '21:00',
        'subtotal' => 175, 'total_price' => 175, 'status' => 'confirmed',
    ]);

    // Booked three days before play, which is what made the two dates
    // indistinguishable in the original report.
    $booking->forceFill(['created_at' => now()->subDays(3)])->save();

    return $booking->fresh()->load('user', 'venue', 'listing');
}

it('shows the booking date, not the date the booking was created', function () {
    $user    = User::factory()->create(['name' => 'Syazwan']);
    $playsOn = now()->addDays(3);
    $booking = bookingFor($user, $playsOn->toDateString());

    foreach (bookingNotifications($booking) as $notification) {
        $body = mailLines($notification, $user);

        expect($body)->toContain('Date: '.$playsOn->format('l, d F Y'));
        expect($body)->not->toContain($booking->created_at->format('l, d F Y'));
        expect($body)->toContain('Time: 07:00 PM - 09:00 PM');
    }
});

it('never substitutes today for a booking that has no date', function () {
    // The actual defect: Carbon::parse(null) is NOW, so this used to print today.
    $user    = User::factory()->create();
    $booking = bookingFor($user, null);

    foreach (bookingNotifications($booking) as $notification) {
        $body = mailLines($notification, $user);

        expect($body)->toContain('Date: Not set');
        expect($body)->not->toContain(now()->format('l, d F Y'));
    }
});

it('stores the booking date on the notification record so no reader falls back to created_at', function () {
    $user    = User::factory()->create();
    $playsOn = now()->addDays(3);
    $booking = bookingFor($user, $playsOn->toDateString());

    foreach (bookingNotifications($booking) as $notification) {
        $payload = $notification->toArray($user);

        expect($payload['date'])->toBe($playsOn->toDateString());
        expect($payload['date_label'])->toBe($playsOn->format('l, d F Y'));
        expect($payload)->toHaveKey('start_time');
        expect($payload)->toHaveKey('end_time');
    }
});

it('writes a long date that cannot be read as another day', function () {
    // 2026-11-04 is read as 11 April by half the world; the long form is not.
    expect(BookingDate::label('2026-11-04'))->toBe('Wednesday, 04 November 2026')
        ->and(BookingDate::iso('2026-11-04'))->toBe('2026-11-04')
        ->and(BookingDate::range('19:00', '21:00'))->toBe('07:00 PM - 09:00 PM');
});

it('treats every unusable date as missing rather than as today', function () {
    foreach ([null, '', '   ', '0000-00-00', 'not a date', []] as $value) {
        expect(BookingDate::label($value))->toBeNull()
            ->and(BookingDate::iso($value))->toBeNull()
            ->and(BookingDate::labelOrUnknown($value))->toBe('Not set');
    }

    expect(BookingDate::range(null, null))->toBeNull()
        ->and(BookingDate::rangeOrUnknown(null, null))->toBe('Not set')
        // One end known is still worth showing; the missing end is named.
        ->and(BookingDate::range('19:00', null))->toBe('07:00 PM - Not set');
});
