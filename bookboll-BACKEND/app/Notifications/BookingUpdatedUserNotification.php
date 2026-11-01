<?php
namespace App\Notifications;

use App\Models\Booking;
use App\Support\BookingDate;
use Illuminate\Bus\Queueable;
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
            ->line('Date: ' . BookingDate::labelOrUnknown($booking->date))
            ->line('Time: ' . BookingDate::rangeOrUnknown($booking->start_time, $booking->end_time))
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
            'booking_id'     => $this->booking->id,
            'booking_number' => $this->booking->booking_id,
            'status'         => 'updated',
            'changes'        => $this->changes,
            // The date AFTER the update — a reschedule notification that showed
            // the old one would be worse than showing none.
            'date'           => BookingDate::iso($this->booking->date),
            'date_label'     => BookingDate::label($this->booking->date),
            'start_time'     => $this->booking->start_time,
            'end_time'       => $this->booking->end_time,
        ];
    }
}

