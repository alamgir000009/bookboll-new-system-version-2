<?php

namespace App\Notifications\HostedEvents;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To everyone in or waiting: the date, time or place moved.
 *
 * Only sent for changes that would send someone to the wrong place at the wrong
 * time — a tweaked description is not worth an email.
 */
class EventUpdatedNotification extends HostedEventNotification
{
    /** @param array<int,string> $changed human labels: 'date', 'start time', 'location' */
    public function __construct($game, public array $changed = [])
    {
        parent::__construct($game);
    }

    public function toMail($notifiable): MailMessage
    {
        $what = $this->changed ? implode(' and ', $this->changed) : 'details';

        $mail = (new MailMessage)
            ->subject('Changed — '.$this->title())
            ->greeting('Hi '.($notifiable->name ?: 'there').',')
            ->line('The host changed the '.$what.' for **'.$this->title().'**.')
            ->line('Here is where it stands now:');

        return $this->details($mail, true)
            ->line("If the new time no longer works, cancel from the {$this->kind()} page so your spot goes to someone else.");
    }

    public function toArray($notifiable): array
    {
        return parent::toArray($notifiable) + ['changed' => $this->changed];
    }
}
