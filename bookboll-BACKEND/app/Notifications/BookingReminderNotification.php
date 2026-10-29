<?php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Queued on purpose: the daily reminder command runs under the shared host's
 * memory and process limits, and sending every email inline over SMTP kept it
 * alive for minutes. The command only inserts the jobs; the queue worker sends.
 * SerializesModels stores the promocode by id, so the job reloads it fresh.
 */
class BookingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(protected int $daysSinceLastBooking, protected $promocode = null)
    {}

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $mail = (new MailMessage)
            ->subject('We Miss You! Come Back and Book Again')
            ->greeting("Hi {$notifiable->name},")
            ->line("It's been **{$this->daysSinceLastBooking} days** since your last booking with us.")
            ->line('We would love to see you again!');

        if ($this->promocode) {
            $mail->line("**Special Offer:** Use code **{$this->promocode->code}** to get {$this->promocode->discount_value}% off your next booking!")
                ->line("This code is valid until " . $this->promocode->expires_at->format('F j, Y') . ".");
        }

        $mail->action('Book Now', config('app.frontend_url'))
            ->line('Thank you for choosing us!');

        return $mail;
    }

    public function toArray($notifiable)
    {
        $data = [
            'message' => "It's been {$this->daysSinceLastBooking} days since your last booking. We miss you!",
            'url'  => config('app.frontend_url'),
            'type' => 'booking_reminder',
        ];

        if ($this->promocode) {
            $data['promocode'] = [
                'code'           => $this->promocode->code,
                'discount_value' => $this->promocode->discount_value,
                'discount_type'  => $this->promocode->discount_type,
                'expires_at'     => $this->promocode->expires_at?->format('Y-m-d'),
            ];
        }

        return $data;
    }
}
