<?php

namespace App\Observers;

use App\Models\Booking;
use App\Support\AdminAlerts;

/** Every booking, however it was created, raises a bell notification for the venue owner. */
class BookingObserver
{
    public function created(Booking $booking): void
    {
        $booking->loadMissing(['listing:id,name', 'venue:id,name', 'user:id,name']);

        AdminAlerts::toVenueOwner(
            $booking->venue_id,
            'booking',
            'ok',
            'New booking — '.($booking->listing?->name ?: $booking->venue?->name ?: 'your venue'),
            implode(' · ', array_filter([$booking->user?->name, $booking->date, $booking->start_time])),
            '/bookings'
        );
    }
}
