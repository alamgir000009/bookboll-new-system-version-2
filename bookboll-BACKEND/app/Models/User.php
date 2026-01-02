<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens, Billable;

    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';
    public const BLOCKED  = 'blocked';
    public const PENDING  = 'pending';

    protected $fillable = [
        'name',
        'image',
        'phone',
        'email',
        'status',
        'gender',
        'address',
        'password',
        'provider',
        'provider_id',
        'nationality',
        'date_of_birth',
        'blocking_reason',
        'loyalty_tier_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'provider',
        'provider_id',
        'blocking_reason',
    ];

    protected function casts(): array
    {
        return [
            'password'          => 'hashed',
            'email_verified_at' => 'datetime',
        ];
    }

    protected $appends = [
        'next_tier',
        'roles_array',
        'total_bookings',
        'loyalty_progress',
        'points_to_next_tier',
    ];

    public function getRolesArrayAttribute()
    {
        return $this->getRoleNames();
    }

    public function getTotalBookingsAttribute()
    {
        return $this->bookings()->count();
    }

    public function getImageAttribute($image)
    {
        if (! $image) {
            return null;
        } elseif (filter_var($image, FILTER_VALIDATE_URL)) {
            return $image;
        }
        return url("storage/{$image}");
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::ACTIVE);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function venues()
    {
        return $this->belongsToMany(Venue::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function helpfulReviews()
    {
        return $this->belongsToMany(Review::class, 'helpful_review', 'user_id', 'review_id')->withTimestamps();
    }

    public function loyaltyPoints()
    {
        return $this->hasMany(LoyaltyPoint::class);
    }

    public function loyaltyTier()
    {
        return $this->belongsTo(LoyaltyTier::class);
    }

    public function calculateLoyaltyTier(int $points): ?LoyaltyTier
    {
        return LoyaltyTier::active()
            ->where('min_points', '<=', $points)
            ->where(function ($query) use ($points) {
                $query->where('max_points', '>=', $points)
                    ->orWhere('max_points', 2147483647);
            })
            ->ordered()
            ->first() ?? LoyaltyTier::where('slug', 'bronze')->first();
    }

    public function getLoyaltyProgressAttribute(): int
    {
        $points      = LoyaltyPoint::balance($this->id) ?? 0;
        $currentTier = $this->loyaltyTier;
        $nextTier    = $currentTier->getNextTier();

        if (! $nextTier) {
            return 100;
        }

        $currentMin = $currentTier->min_points;
        $nextMin    = $nextTier->min_points;

        if ($nextMin <= $currentMin) {
            return 100;
        }

        $progress = (($points - $currentMin) / ($nextMin - $currentMin)) * 100;
        return min(100, max(0, (int) round($progress)));
    }

    public function getNextTierAttribute()
    {
        return $this->loyaltyTier->getNextTier();
    }

    public function getLoyaltyTierAttribute()
    {
        if ($this->loyalty_tier_id) {
            return $this->loyaltyTier()->first();
        }

        return $this->calculateLoyaltyTier(LoyaltyPoint::balance($this->id) ?? 0);
    }

    public function getPointsToNextTierAttribute(): int
    {
        $points   = LoyaltyPoint::balance($this->id) ?? 0;
        $nextTier = $this->next_tier;

        if (! $nextTier) {
            return 0;
        }

        return max(0, $nextTier->min_points - $points);
    }

    public function updateLoyaltyTier(): bool
    {
        $newTier = $this->calculateLoyaltyTier(LoyaltyPoint::balance($this->id) ?? 0);

        if ($this->loyalty_tier_id != $newTier?->id) {
            $this->loyalty_tier_id = $newTier?->id;
            $this->save();
        }

        return true;
    }

    public function addLoyaltyPoints(int $points, $booking_id = null, $reason = 'added by system'): void
    {
        $this->loyaltyPoints()->create([
            'points'     => $points,
            'reason'     => $reason,
            'type'       => 'credit',
            'booking_id' => $booking_id,
        ]);
        $this->updateLoyaltyTier();
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function promocodes()
    {
        return $this->belongsToMany(Promocode::class, 'promocode_user')->withPivot('usage_count')->withTimestamps();
    }

    public function preferences()
    {
        return $this->hasOne(Preference::class);
    }

    public function onesignalSubscriptions()
    {
        return $this->hasMany(OneSignalSubscription::class);
    }

    public function activeOnesignalSubscriptions()
    {
        return $this->hasMany(OneSignalSubscription::class)->where('is_active', true);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps()->orderBy('name');
    }

    public function syncTags(array $tagNames)
    {
        $tags = collect($tagNames)
            ->map(fn($name) => trim(strtolower($name)))
            ->filter()
            ->unique()
            ->map(function ($name) {
                return Tag::firstOrCreate(
                    ['name' => $name],
                    ['color' => $this->generateTagColor($name)]
                );
            });

        $this->tags()->sync($tags->pluck('id'));

        return $this->load('tags');
    }

    private function generateTagColor($name)
    {
        $colors = [
            'vip'       => 'bg-purple-100 text-purple-800',
            'corporate' => 'bg-blue-100 text-blue-800',
            'regular'   => 'bg-green-100 text-green-800',
            'new'       => 'bg-yellow-100 text-yellow-800',
        ];

        return $colors[$name] ?? 'bg-gray-100 text-gray-800';
    }
}
