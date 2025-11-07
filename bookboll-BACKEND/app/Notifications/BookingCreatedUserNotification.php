<?php
namespace App\Notifications;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class BookingCreatedUserNotification extends Notification implements ShouldQueue
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
            ->subject('Your Booking is Created! - #' . $booking->reference_number)
            ->greeting('Hi ' . $booking->user->name . ',')
            ->line('Your booking for ' . $booking->listing->name . ' at ' . $booking->venue->name . ' has been confirmed.')
            ->line('Date: ' . $booking->date)
            ->line('Time: ' . Carbon::parse($booking->start_time)->format('h:i A') . ' - ' . Carbon::parse($booking->end_time)->format('h:i A'))
            ->line('Total: QAR' . number_format($booking->total_price ?? 0, 2))
            ->action('View Booking', config('app.frontend_url'))
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
            'booking_id'   => $this->booking->id,
            'venue_name'   => $this->booking->venue->name,
            'listing_name' => $this->booking->listing->name,
            'total_price'  => $this->booking->total_price,
        ];
    }
}
