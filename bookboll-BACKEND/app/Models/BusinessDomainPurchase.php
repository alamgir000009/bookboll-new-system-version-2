<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment of the custom-domain setup fee.
 *
 * A row is created when the provider opens the Stripe checkout (status `pending`)
 * and only becomes `paid` when Stripe says the money moved — which is also the only
 * moment the domain is written onto the business. An abandoned checkout stays
 * `pending` and then `expired`, so support can see what was attempted.
 */
class BusinessDomainPurchase extends Model
{
    use LogsModelActivity;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID    = 'paid';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'business_id',
        'domain',
        'amount',
        'currency',
        'status',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'  => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    protected function activityLogName(): string
    {
        return 'business_domain_purchase';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'business_id',
            'domain',
            'amount',
            'currency',
            'status',
            'stripe_payment_intent_id',
            'paid_at',
        ];
    }
}
