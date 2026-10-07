<?php

namespace App\Notifications\HostedEvents;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the HOST: someone who had a spot dropped out.
 *
 * Worth an email because it changes the host's plans — a five-a-side with four
 * players needs them to do something about it.
 */
class EventParticipantLeftNotification extends HostedEventNotification
{
    public function __construct($game, public string $joinerName = '', public bool $hadSpot = true, public int $spotsLeft = 0)
    {
        parent::__construct($game);
    }

    public function toMail($notifiable): MailMessage
    {
        $who = $this->joinerName ?: 'Someone';

        $mail = (new MailMessage)
            ->subject('Someone dropped out — '.$this->title())
            ->greeting('Hi '.($notifiable->name ?: 'there').',')
            ->line($this->hadSpot
                ? '**'.$who.'** has dropped out of **'.$this->title().'**.'
                : '**'.$who.'** has withdrawn their request to join **'.$this->title().'**.');

        if ($this->hadSpot && $this->spotsLeft > 0) {
            $mail->line('That leaves **'.$this->spotsLeft.' spot'.($this->spotsLeft === 1 ? '' : 's').'** open. Anyone on the waitlist has been moved up automatically.');
        }

        return $this->details($mail);
    }

    public function toArray($notifiable): array
    {
        return parent::toArray($notifiable) + ['joiner_name' => $this->joinerName, 'spots_left' => $this->spotsLeft];
    }
}
