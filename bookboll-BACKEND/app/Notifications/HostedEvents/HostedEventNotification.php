<?php

namespace App\Notifications\HostedEvents;

use App\Models\HostedGame;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shared shape for every hosted-event email.
 *
 * Events had no mail at all: a host learned their event filled up only by opening
 * the app, and a joiner whose event was cancelled found out by turning up. These
 * cover the moments where someone is waiting on the other person to act.
 *
 * Subclasses write the subject and the one-line story; when, where and the link
 * come from here so all eight read like the same product. Sent on the mail AND
 * database channels, so the in-app bell keeps working exactly as before.
 */
abstract class HostedEventNotification extends Notification
{
    use Queueable;

    public function __construct(public HostedGame $game)
    {
    }

    public function via(): array
    {
        return ['mail', 'database'];
    }

    /** "game" / "hangout" / "tournament" — the word the rest of the product uses. */
    protected function kind(): string
    {
        return match ($this->game->game_type) {
            'tournament' => 'tournament',
            'event'      => 'hangout',
            default      => 'game',
        };
    }

    protected function title(): string
    {
        return $this->game->title ?: ucfirst($this->kind());
    }

    /** "Tue, 7 Oct · 5:00 PM – 6:00 PM" */
    protected function when(): string
    {
        $date = $this->game->game_date ? Carbon::parse($this->game->game_date)->format('D, j M') : '';
        $from = $this->game->start_time ? Carbon::parse($this->game->start_time)->format('g:i A') : '';
        $to   = $this->game->end_time ? Carbon::parse($this->game->end_time)->format('g:i A') : '';
        $time = trim($from.($to ? ' – '.$to : ''));

        return trim($date.($date && $time ? ' · ' : '').$time);
    }

    /**
     * Where it happens. An own-spot host can keep the exact address private until
     * they accept someone, so the address is only ever added by the notifications
     * that go to people who are actually in.
     */
    protected function where(bool $withAddress = false): string
    {
        $place = $this->game->location_id
            ? (Venue::find($this->game->location_id)?->name ?: '')
            : (string) $this->game->custom_location_name;

        if ($withAddress && ! $this->game->location_id && filled($this->game->custom_location_address)) {
            $place = trim($place.' — '.$this->game->custom_location_address);
        }

        return $place;
    }

    protected function url(): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $path = $this->game->game_type === 'tournament' ? 'tournaments' : 'games';

        return $base.'/'.$path.'/'.$this->game->uuid;
    }

    /** The block every one of these emails ends with. */
    protected function details(MailMessage $mail, bool $withAddress = false): MailMessage
    {
        if ($when = $this->when()) {
            $mail->line('**When:** '.$when);
        }

        if ($where = $this->where($withAddress)) {
            $mail->line('**Where:** '.$where);
        }

        return $mail->action('View '.$this->kind(), $this->url());
    }

    public function toArray($notifiable): array
    {
        return [
            'type'       => static::class,
            'game_id'    => $this->game->uuid,
            'game_type'  => $this->game->game_type,
            'title'      => $this->title(),
            'game_date'  => $this->game->game_date ? Carbon::parse($this->game->game_date)->toDateString() : null,
            'start_time' => $this->game->start_time,
            'url'        => $this->url(),
        ];
    }
}
