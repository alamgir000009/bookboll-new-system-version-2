<?php

namespace App\Observers;

use App\Models\Booking;
use App\Support\AdminAlerts;
use BookingStatus;

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
            $this->subtitle($booking),
            '/bookings',
            'booking_new'
        );
    }

    /**
     * A cancellation is told to the owner too.
     *
     * Settings offered a "Booking cancellation" switch for an alert nothing
     * raised. This is the alert it governs.
     */
    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged('status') || $booking->status !== BookingStatus::CANCELLED) {
            return;
        }

        $booking->loadMissing(['listing:id,name', 'venue:id,name', 'user:id,name']);

        AdminAlerts::toVenueOwner(
            $booking->venue_id,
            'booking',
            'warn',
            'Booking cancelled — '.($booking->listing?->name ?: $booking->venue?->name ?: 'your venue'),
            $this->subtitle($booking),
            '/bookings',
            'booking_cancelled'
        );
    }

    protected function subtitle(Booking $booking): string
    {
        return implode(' · ', array_filter([$booking->user?->name, $booking->date, $booking->start_time]));
    }
}
