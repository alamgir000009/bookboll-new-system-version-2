<?php
namespace App\Notifications;

use App\Models\Booking;
use BookingStatus;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCancelledUserNotification extends Notification implements ShouldQueue
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

        $mail = (new MailMessage)
            ->subject('Your Booking Has Been Cancelled')
            ->greeting('Hi ' . $booking->user->name . ',')
            ->line('We regret to inform you that your booking for ' . $booking->listing->name . ' at ' . $booking->venue->name . ' has been cancelled.')
            ->line('Date: ' . $booking->date)
            ->line('Time: ' . Carbon::parse($booking->start_time)->format('h:i A') . ' - ' . Carbon::parse($booking->end_time)->format('h:i A'))
            ->line('Cancel Reason: ' . ($booking->cancel_reason ?? 'N/A'));

        if ($booking->venue->maps_url) {
            $mail->line('Venue Location: ' . $booking->venue->maps_url);
        }

        $mail->line('If this was not intended, please contact support or try booking again.')
            ->action('View Your Bookings', config('app.frontend_url'))
            ->line('We hope to serve you again soon.');

        return $mail;
    }

    public function toArray($notifiable)
    {
        return [
            'booking_id'   => $this->booking->id,
            'venue_name'   => $this->booking->venue->name,
            'listing_name' => $this->booking->listing->name,
            'status'       => BookingStatus::CANCELLED,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ];
    }
}
