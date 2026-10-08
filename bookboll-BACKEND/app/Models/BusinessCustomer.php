<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The explicit customer → business link.
 *
 * `owner_user_id` is the business (a provider_admin's user id, the identity
 * AccessScope scopes by); `user_id` is the customer. `source` says why the link
 * exists: 'added' (typed in by the business), 'booked', or 'subscribed'.
 *
 * A customer may hold several rows — one business added them, another took a
 * booking from them. See App\Support\AccessScope::applyCustomerScope().
 */
class BusinessCustomer extends Model
{
    protected $table = 'business_customer';

    protected $fillable = ['owner_user_id', 'user_id', 'source'];

    public const SOURCE_ADDED      = 'added';
    public const SOURCE_BOOKED     = 'booked';
    public const SOURCE_SUBSCRIBED = 'subscribed';

    /** Record a link, ignoring one that already exists. */
    public static function link(?int $ownerUserId, ?int $customerId, string $source = self::SOURCE_ADDED): void
    {
        if (! $ownerUserId || ! $customerId || $ownerUserId === $customerId) {
            return;
        }

        static::firstOrCreate([
            'owner_user_id' => $ownerUserId,
            'user_id'       => $customerId,
            'source'        => $source,
        ]);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
