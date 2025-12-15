<?php
namespace App\Notifications;

use App\Models\Booking;
use BookingStatus;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmedUserNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking)
    {}

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $mail = (new MailMessage)
            ->subject('Your Booking Has Been Confirmed! - #' . $this->booking->booking_id)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your booking #' . $this->booking->booking_id . ' has been confirmed.')
            ->line('**Details:**')
            ->line('Venue: ' . $this->booking->venue->name)
            ->line('Activity: ' . $this->booking->listing->name)
            ->line('Date: ' . $this->booking->date)
            ->line('Time: ' . Carbon::parse($this->booking->start_time)->format('h:i A') . ' - ' . Carbon::parse($this->booking->end_time)->format('h:i A'))
            ->line('Total: QAR ' . number_format($this->booking->total_price, 2))
            ->line('Status: Confirmed');

        if ($this->booking->venue->maps_url) {
            $mail->action('View Location on Google Maps', $this->booking->venue->maps_url);
        }

        $mail->action('View Booking', config('app.frontend_url'))
            ->line('If you have any questions, contact us.')
            ->salutation('Best regards, BookBoll Team');

        return $mail;
    }

    public function toArray($notifiable)
    {
        return [
            'booking_id' => $this->booking->id,
            'status'     => BookingStatus::CONFIRMED,
        ];
    }
}
