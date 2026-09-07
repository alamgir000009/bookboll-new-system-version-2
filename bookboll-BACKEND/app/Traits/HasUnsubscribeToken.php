<?php

namespace App\Traits;

use Illuminate\Support\Str;

/**
 * A stable, unguessable token so a marketing email's one-click unsubscribe link
 * works without a sign-in. Minted at creation; `ensureUnsubscribeToken()` covers
 * rows that predate the column.
 */
trait HasUnsubscribeToken
{
    protected static function bootHasUnsubscribeToken(): void
    {
        static::creating(function ($model) {
            if (empty($model->unsubscribe_token)) {
                $model->unsubscribe_token = Str::random(48);
            }
        });
    }

    public function ensureUnsubscribeToken(): string
    {
        if (empty($this->unsubscribe_token)) {
            $this->forceFill(['unsubscribe_token' => Str::random(48)])->save();
        }

        return (string) $this->unsubscribe_token;
    }
}
