<?php

namespace App\Notifications\HostedEvents;

use Illuminate\Notifications\Messages\MailMessage;

/** To the joiner: the host reviews requests, so theirs is waiting on a decision. */
class EventJoinRequestedNotification extends HostedEventNotification
{
    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Request sent — '.$this->title())
            ->greeting('Hi '.($notifiable->name ?: 'there').',')
            ->line('Your request to join **'.$this->title().'** is with the host.')
            ->line("You'll get an email the moment they accept or decline — you don't need to do anything else for now.");

        return $this->details($mail);
    }
}
