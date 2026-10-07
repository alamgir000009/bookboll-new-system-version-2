<?php

namespace App\Notifications\HostedEvents;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the HOST: somebody wants in.
 *
 * With review mode the request sits there until the host acts, so this is the
 * only thing that tells them to go and act. With instant join nothing is needed
 * from them — the wording says so rather than nagging.
 */
class EventRequestReceivedNotification extends HostedEventNotification
{
    public function __construct($game, public string $joinerName = '', public bool $instant = false, public string $message = '')
    {
        parent::__construct($game);
    }

    public function toMail($notifiable): MailMessage
    {
        $who = $this->joinerName ?: 'Someone';

        $mail = (new MailMessage)
            ->subject(($this->instant ? 'New player joined — ' : 'New request to join — ').$this->title())
            ->greeting('Hi '.($notifiable->name ?: 'there').',')
            ->line($this->instant
                ? '**'.$who.'** has joined **'.$this->title().'**. Your event is set to instant join, so there is nothing for you to approve.'
                : '**'.$who.'** has asked to join **'.$this->title().'**.');

        if (filled($this->message)) {
            $mail->line('They said: "'.$this->message.'"');
        }

        if (! $this->instant) {
            $mail->line('Accept or decline them on the event page — they are waiting to hear back.');
        }

        return $this->details($mail);
    }

    public function toArray($notifiable): array
    {
        return parent::toArray($notifiable) + ['joiner_name' => $this->joinerName, 'instant' => $this->instant];
    }
}
