<?php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected int $daysSinceLastBooking)
    {}

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('We Miss You! Come Back and Book Again')
            ->greeting("Hi {$notifiable->name},")
            ->line("It's been **{$this->daysSinceLastBooking} days** since your last booking with us.")
            ->line('We would love to see you again!')
            ->action('Book Now', config('app.frontend_url'))
            ->line('Thank you for choosing us!');
    }

    public function toArray($notifiable)
    {
        return [
            'message' => "It's been {$this->daysSinceLastBooking} days since your last booking. We miss you!",
            'url'  => config('app.frontend_url'),
            'type' => 'booking_reminder',
        ];
    }
}
