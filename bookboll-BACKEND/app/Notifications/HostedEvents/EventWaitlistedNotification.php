<?php

namespace App\Notifications\HostedEvents;

use Illuminate\Notifications\Messages\MailMessage;

/** To the joiner: it was full, so they are queued rather than turned away. */
class EventWaitlistedNotification extends HostedEventNotification
{
    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("You're on the waitlist — ".$this->title())
            ->greeting('Hi '.($notifiable->name ?: 'there').',')
            ->line('**'.$this->title().'** is full, so you are on the waitlist.')
            ->line("If anyone drops out, the longest-waiting person gets the spot — we'll email you if that's you.");

        return $this->details($mail);
    }
}
