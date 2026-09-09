<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider's Bookboll for Business PREMIUM subscription, as Stripe reports it.
 *
 * Nothing here is decided locally: every field is written from a Stripe subscription
 * we have read back ourselves. A row exists from the moment a checkout is opened
 * (status `incomplete`), so an abandoned checkout is visible as exactly that rather
 * than as silence.
 */
class BusinessSubscription extends Model
{
    /** Statuses that still entitle the provider to the Premium features. */
    public const ENTITLING = ['trialing', 'active', 'past_due'];

    protected $fillable = [
        'business_id',
        'plan',
        'billing',
        'status',
        'amount',
        'currency',
        'trial_ends_at',
        'current_period_end',
        'cancel_at_period_end',
        'started_at',
        'ended_at',
        'stripe_subscription_id',
        'stripe_customer_id',
        'stripe_checkout_session_id',
        'last_event_at',
        'promocode_id',
        'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'amount'               => 'decimal:2',
            'discount_amount'      => 'decimal:2',
            'trial_ends_at'        => 'datetime',
            'current_period_end'   => 'datetime',
            'started_at'           => 'datetime',
            'ended_at'             => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'last_event_at'        => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function promocode(): BelongsTo
    {
        return $this->belongsTo(Promocode::class);
    }

    /** Does this row entitle its business to the Premium features right now? */
    public function isEntitling(): bool
    {
        return in_array($this->status, self::ENTITLING, true);
    }

    public function scopeEntitling($query)
    {
        return $query->whereIn('status', self::ENTITLING);
    }

    /** True while the provider is inside a free trial. */
    public function onTrial(): bool
    {
        return $this->status === 'trialing'
            && $this->trial_ends_at
            && $this->trial_ends_at->isFuture();
    }
}
