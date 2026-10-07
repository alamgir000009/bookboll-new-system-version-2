<?php

namespace App\Notifications\HostedEvents;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the joiner: they are in. One class covers every route to a spot — instant
 * join, the host accepting, and a waitlist promotion — because the only thing
 * that differs is the opening line.
 */
class EventJoinConfirmedNotification extends HostedEventNotification
{
    /** @param 'instant'|'accepted'|'waitlist' $reason how they got in */
    public function __construct($game, public string $reason = 'accepted')
    {
        parent::__construct($game);
    }

    public function toMail($notifiable): MailMessage
    {
        $opening = match ($this->reason) {
            'instant'  => "You're in — your spot at **".$this->title().'** is confirmed.',
            'waitlist' => 'A spot opened up and it went to you — you are now in **'.$this->title().'**.',
            default    => 'The host accepted your request — you are in **'.$this->title().'**.',
        };

        $mail = (new MailMessage)
            ->subject("You're in — ".$this->title())
            ->greeting('Hi '.($notifiable->name ?: 'there').',')
            ->line($opening);

        // Confirmed people are the only ones who get a hidden exact address.
        $mail = $this->details($mail, true);

        if ((float) $this->game->price_per_person > 0) {
            $mail->line('**To pay:** '.$this->game->currency.' '
                .number_format((float) $this->game->price_per_person, 2).' per person — settle it with the host.');
        }

        return $mail->line("If your plans change, cancel from the {$this->kind()} page so someone on the waitlist can take your spot.");
    }

    public function toArray($notifiable): array
    {
        return parent::toArray($notifiable) + ['reason' => $this->reason];
    }
}
