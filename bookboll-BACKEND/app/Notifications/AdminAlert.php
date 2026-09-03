<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One notification type for the dashboard bell.
 *
 * The payload is exactly what the bell renders, so the feed endpoint is a
 * straight read with no per-type mapping:
 *
 *   kind  booking | member | approval | wallet | provider   (picks the icon)
 *   tone  ok | info | warn                                   (picks the colour)
 *   link  where clicking it goes
 */
class AdminAlert extends Notification
{
    public function __construct(
        public string $kind,
        public string $tone,
        public string $title,
        public string $sub = '',
        public string $link = '/admin',
    ) {}

    /** @return list<string> */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toArray($notifiable): array
    {
        return [
            'kind'  => $this->kind,
            'tone'  => $this->tone,
            'title' => $this->title,
            'sub'   => $this->sub,
            'link'  => $this->link,
        ];
    }
}
