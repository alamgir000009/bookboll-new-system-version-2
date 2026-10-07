<?php

namespace App\Notifications\HostedEvents;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the joiner: they are not in. Covers the host declining a request and the
 * host removing someone who had already been accepted — the second is the
 * harsher one, so it says plainly that they had a spot and no longer do.
 */
class EventJoinDeclinedNotification extends HostedEventNotification
{
    public function __construct($game, public bool $wasAccepted = false)
    {
        parent::__construct($game);
    }

    public function toMail($notifiable): MailMessage
    {
        $line = $this->wasAccepted
            ? 'The host has removed your spot at **'.$this->title().'**.'
            : "The host couldn't fit you into **".$this->title().'** this time.';

        $mail = (new MailMessage)
            ->subject(($this->wasAccepted ? 'Your spot was removed — ' : 'Not this time — ').$this->title())
            ->greeting('Hi '.($notifiable->name ?: 'there').',')
            ->line($line)
            ->line('Nothing was charged to you. There are other '.$this->kind().'s on Bookboll looking for people.');

        return $this->details($mail);
    }

    public function toArray($notifiable): array
    {
        return parent::toArray($notifiable) + ['was_accepted' => $this->wasAccepted];
    }
}
