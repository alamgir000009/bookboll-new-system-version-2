<?php
namespace App\Notifications;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingUpdatedUserNotification extends Notification
{
    use Queueable;

    public function __construct(public Booking $booking, public array $changes = [])
    {}

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $booking = $this->booking;

        $mail = (new MailMessage)
            ->subject('Your Booking Has Been Updated - #' . $booking->booking_id)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your booking #' . $booking->booking_id . ' has been updated.');

        if (!empty($this->changes)) {
            $mail->line('**Changes made:**');
            foreach ($this->changes as $change) {
                $mail->line('• ' . $change);
            }
        }

        $mail->line('**Updated Booking Details:**')
            ->line('Venue: ' . $booking->venue->name)
            ->line('Activity: ' . $booking->listing->name)
            ->line('Date: ' . $booking->date)
            ->line('Time: ' . Carbon::parse($booking->start_time)->format('h:i A') . ' - ' . Carbon::parse($booking->end_time)->format('h:i A'))
            ->line('Total: QAR ' . number_format($booking->total_price, 2))
            ->line('Status: ' . ucfirst($booking->status));

        if ($booking->addOns->isNotEmpty()) {
            $addOnsList = $booking->addOns->pluck('name')->implode(', ');
            $mail->line('Add-ons: ' . $addOnsList);
        }

        if ($booking->payment_method) {
            $paymentMethod = str_replace('_', ' ', $booking->payment_method);
            $mail->line('Payment Method: ' . ucwords($paymentMethod));
        }

        if ($booking->payment_status) {
            $paymentStatus = str_replace('_', ' ', $booking->payment_status);
            $mail->line('Payment Status: ' . ucwords($paymentStatus));
        }

        if ($booking->venue->maps_url) {
            $mail->action('View Location on Google Maps', $booking->venue->maps_url);
        }

        $mail->action('View Booking', config('app.frontend_url'))
            ->line('If you have any questions about these changes, please contact us.')
            ->salutation('Best regards, ' . config('app.name') . ' Team');

        return $mail;
    }

    public function toArray($notifiable)
    {
        return [
            'booking_id' => $this->booking->id,
            'status'     => 'updated',
            'changes'    => $this->changes,
        ];
    }
}

