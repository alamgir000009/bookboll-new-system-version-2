<?php
namespace App\Notifications;

use App\Models\Booking;
use App\Support\BookingDate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class BookingCreatedUserNotification extends Notification
{
    use Queueable;

    public function __construct(public Booking $booking, public $invoice_path = null)
    {}

    public function via()
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $booking = $this->booking;

        $mail = (new MailMessage)
            ->subject('Your Booking is Created! - #' . $booking->booking_id)
            ->greeting('Hi ' . $booking->user->name . ',')
            ->line('Your booking for ' . $booking->listing->name . ' at ' . $booking->venue->name . ' has been successfully booked.')
            ->line('Date: ' . BookingDate::labelOrUnknown($booking->date))
            ->line('Time: ' . BookingDate::rangeOrUnknown($booking->start_time, $booking->end_time))
            ->line('Total: QAR' . number_format($booking->total_price ?? 0, 2));

        if ($booking->venue->maps_url) {
            $mail->action('View Location on Google Maps', $booking->venue->maps_url);
        }

        $mail->action('View Booking', config('app.frontend_url'))
            ->line('We look forward to seeing you!');

        if ($this->invoice_path && Storage::disk('public')->exists($this->invoice_path)) {
            $mail->attach(Storage::disk('public')->path($this->invoice_path), [
                'as'   => 'Invoice-' . $booking->id . '.pdf',
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }

    public function toArray($notifiable)
    {
        return [
            'booking_id'     => $this->booking->id,
            'booking_number' => $this->booking->booking_id,
            'venue_name'     => $this->booking->venue->name,
            'listing_name'   => $this->booking->listing->name,
            'total_price'    => $this->booking->total_price,
            'date'           => BookingDate::iso($this->booking->date),
            'date_label'     => BookingDate::label($this->booking->date),
            'start_time'     => $this->booking->start_time,
            'end_time'       => $this->booking->end_time,
        ];
    }
}
