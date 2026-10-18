<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A single-use offer of how many points one specific checkout may spend. */
class LoyaltyCheckoutQuote extends Model
{
    protected $fillable = [
        'quote_id', 'user_id', 'country_id', 'currency', 'kind',
        'points', 'discount_minor', 'order_total_minor', 'due_now_minor',
        'commission_minor', 'event_fee_minor', 'booking_fee_minor',
        'inputs_hash', 'expires_at', 'reserved_at', 'consumed_at', 'released_at',
        'idempotency_key',
    ];

    protected $casts = [
        'points'            => 'integer',
        'discount_minor'    => 'integer',
        'order_total_minor' => 'integer',
        'due_now_minor'     => 'integer',
        'commission_minor'  => 'integer',
        'event_fee_minor'   => 'integer',
        'booking_fee_minor' => 'integer',
        'expires_at'        => 'datetime',
        'reserved_at'       => 'datetime',
        'consumed_at'       => 'datetime',
        'released_at'       => 'datetime',
    ];

    public function isExpired(): bool
    {
        return ! $this->expires_at || $this->expires_at->isPast();
    }

    /** Spent already, or released back — either way it cannot be used again. */
    public function isSpent(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }

    /**
     * Usable by this account, for this order, right now.
     *
     * Every clause is a refusal the document asks for by name: expired, already
     * used, wrong account, wrong country, wrong currency, or an order that has
     * changed since it was priced.
     */
    public function usableFor(int $userId, int $countryId, string $currency, string $inputsHash): bool
    {
        return ! $this->isExpired()
            && ! $this->isSpent()
            && ! $this->isReleased()
            && (int) $this->user_id === $userId
            && (int) $this->country_id === $countryId
            && strtoupper($this->currency) === strtoupper($currency)
            && hash_equals($this->inputs_hash, $inputsHash);
    }
}
