<?php

namespace App\Notifications\HostedEvents;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To everyone holding a spot, a pending request or a waitlist place: it is off.
 *
 * The one email nobody can afford to miss — without it the first someone hears of
 * a cancellation is standing at the venue.
 */
class EventCancelledNotification extends HostedEventNotification
{
    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Cancelled — '.$this->title())
            ->greeting('Hi '.($notifiable->name ?: 'there').',')
            ->line('The host has cancelled **'.$this->title().'**, so it is not going ahead.')
            ->line('Please do not turn up. Nothing is owed to the host.');

        return $this->details($mail);
    }
}
