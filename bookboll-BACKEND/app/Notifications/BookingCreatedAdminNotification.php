<?php
namespace App\Notifications;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCreatedAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking)
    {}

    public function via()
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $booking = $this->booking;

        return (new MailMessage)
            ->subject('New Booking Received')
            ->greeting('Hello Admin,')
            ->line('A new booking has been confirmed.')
            ->line('Customer: ' . $booking->user->name)
            ->line('Venue: ' . $booking->venue->name)
            ->line('Listing: ' . $booking->listing->name)
            ->line('Date: ' . $booking->date)
            ->line('Time: ' . Carbon::parse($booking->start_time)->format('h:i A') . ' - ' . Carbon::parse($booking->end_time)->format('h:i A'))
            ->line('Total: $' . number_format($booking->total_price ?? 0, 2))
            ->action('View Booking', config('app.admin_url').'/bookings/' . $booking->id)
            ->line('Thank you for using ' . config('app.name') . '!');
    }

    public function toArray()
    {
        return [
            'booking_id'   => $this->booking->id,
            'venue_name'   => $this->booking->venue->name,
            'listing_name' => $this->booking->listing->name,
            'total_price'  => $this->booking->total_price,
        ];
    }
}
