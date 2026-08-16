<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class Promocode extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'code',
        'status',
        'valid_from',
        'expires_at',
        'used_count',
        'usage_limit',
        'description',
        'discount_type',
        'used_by_users',
        'discount_value',
        'per_user_limit',
        'payment_methods',
        'min_order_amount',
        'max_discount_amount',
        'is_reminder_promocode',
        'is_gift_card_promocode',
        'gift_card_id',
        'recipient',
        'creator_role',
        'owner_user_id',
        'applicable_to',
        'location_id',
        'owner_user_ids',
        'location_ids',
        'listing_ids',
        'program_ids',
        'audience',
        'target_emails',
        'per_customer_limit',
    ];

    protected $casts = [
        'valid_from'             => 'date',
        'expires_at'             => 'date',
        'used_by_users'          => 'array',
        'discount_value'         => 'decimal:2',
        'is_reminder_promocode'  => 'boolean',
        'is_gift_card_promocode' => 'boolean',
        'min_order_amount'       => 'decimal:2',
        'max_discount_amount'    => 'decimal:2',
        'owner_user_ids'         => 'array',
        'location_ids'           => 'array',
        'listing_ids'            => 'array',
        'program_ids'            => 'array',
        'target_emails'          => 'array',
        'per_customer_limit'     => 'integer',
    ];

    public function venues()
    {
        return $this->belongsToMany(Venue::class);
    }

    public function listings()
    {
        return $this->belongsToMany(Listing::class);
    }

    public function giftCard()
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function scopeGiftCardPromocodes($query)
    {
        return $query->where('is_gift_card_promocode', true);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'promocode_user')->withPivot('usage_count')->withTimestamps();
    }

    public function redemptions()
    {
        return $this->hasMany(PromocodeRedemption::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            });
    }

    public function scopeReminderPromocodes($query)
    {
        return $query->where('is_reminder_promocode', true);
    }

    public function scopeNonReminderPromocodes($query)
    {
        return $query->where('is_reminder_promocode', false);
    }

    public function isValidForOrder($orderAmount, $userId = null, $paymentMethod = null, $venueId = null, $listingId = null)
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->valid_from && now()->lt($this->valid_from)) {
            return false;
        }

        if ($this->expires_at && now()->gt($this->expires_at)) {
            return false;
        }

        if ($this->min_order_amount && $orderAmount < $this->min_order_amount) {
            return false;
        }

        if ($this->payment_methods !== 'all') {
            if ($this->payment_methods == 'online_only' && !in_array($paymentMethod, ['online', 'online_payment'])) {
                return false;
            }
        }

        if ($venueId && $this->venues->isNotEmpty() && ! $this->venues->contains($venueId)) {
            return false;
        }

        if ($listingId && $this->listings->isNotEmpty() && ! $this->listings->contains($listingId)) {
            return false;
        }

        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return false;
        }

        if ($this->per_user_limit && $userId) {
            $userUsed = $this->used_by_users[$userId] ?? 0;
            if ($userUsed >= $this->per_user_limit) {
                return false;
            }
        }

        if ($userId) {
            if (! $this->relationLoaded('users')) {
                $this->load('users');
            }
            $assignedUserIds = $this->users->pluck('id')->toArray();
            if (! empty($assignedUserIds) && ! in_array($userId, $assignedUserIds)) {
                return false;
            }
        }

        return true;
    }

    public function applyDiscount($orderAmount)
    {
        $discount = 0;

        if ($this->discount_type == 'percentage') {
            $discount = ($orderAmount * $this->discount_value) / 100;
        } elseif ($this->discount_type == 'fixed') {
            $discount = $this->discount_value;
        }

        if ($this->max_discount_amount && $discount > $this->max_discount_amount) {
            $discount = $this->max_discount_amount;
        }

        if ($discount > $orderAmount) {
            $discount = $orderAmount;
        }

        return $discount;
    }

    public function incrementUsage($userId = null)
    {
        $this->increment('used_count');

        if ($userId) {
            $usedByUsers          = $this->used_by_users;
            $usedByUsers[$userId] = ($usedByUsers[$userId] ?? 0) + 1;
            $this->used_by_users  = $usedByUsers;
            $this->save();
        }
    }

    public function decrementUsage($userId = null)
    {
        $this->decrement('used_count');

        if ($userId) {
            $usedByUsers = $this->used_by_users;
            if (isset($usedByUsers[$userId]) && $usedByUsers[$userId] > 0) {
                $usedByUsers[$userId]--;
                if ($usedByUsers[$userId] === 0) {
                    unset($usedByUsers[$userId]);
                }
                $this->used_by_users = $usedByUsers;
                $this->save();
            }
        }
    }

    public function getFormattedDiscountAttribute()
    {
        if ($this->discount_type == 'percentage') {
            return $this->discount_value . '%';
        }
        return 'QAR ' . number_format($this->discount_value, 2);
    }

    public function getNeverExpiresAttribute()
    {
        return is_null($this->expires_at);
    }

    public function isAvailableToAllUsers(): bool
    {
        if (! $this->relationLoaded('users')) {
            $this->load('users');
        }
        return $this->users->isEmpty();
    }

    public function isAvailableToUser(int $userId): bool
    {
        if ($this->isAvailableToAllUsers()) {
            return true;
        }

        if (! $this->relationLoaded('users')) {
            $this->load('users');
        }
        return $this->users->contains('id', $userId);
    }
    protected function activityLogName(): string
    {
        return 'promocode';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'code',
            'status',
            'valid_from',
            'expires_at',
            'used_count',
            'usage_limit',
            'description',
            'discount_type',
            'used_by_users',
            'discount_value',
            'per_user_limit',
            'payment_methods',
            'min_order_amount',
            'max_discount_amount',
            'is_reminder_promocode',
            'is_gift_card_promocode',
            'gift_card_id',
            'recipient',
            'creator_role',
            'owner_user_id',
            'applicable_to',
            'audience',
        ];
    }

}