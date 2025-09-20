<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promocode extends Model
{
    protected $fillable = [
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
    ];

    protected $casts = [
        'discount_value'      => 'decimal:2',
        'min_order_amount'    => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'valid_from'          => 'date',
        'expires_at'          => 'date',
        'used_by_users'       => 'array',
    ];

    public function venues()
    {
        return $this->belongsToMany(Venue::class);
    }

    public function listings()
    {
        return $this->belongsToMany(Listing::class);
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
            if ($this->payment_methods === 'online_only' && $paymentMethod !== 'online') {
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

        return true;
    }

    public function applyDiscount($orderAmount)
    {
        $discount = 0;

        if ($this->discount_type === 'percentage') {
            $discount = ($orderAmount * $this->discount_value) / 100;
        } elseif ($this->discount_type === 'fixed') {
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

    public function getFormattedDiscountAttribute()
    {
        if ($this->discount_type === 'percentage') {
            return $this->discount_value . '%';
        }
        return 'QAR ' . number_format($this->discount_value, 2);
    }

    public function getNeverExpiresAttribute()
    {
        return is_null($this->expires_at);
    }
}
