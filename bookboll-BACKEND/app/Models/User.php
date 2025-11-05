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
    ];

    protected $hidden = [
        'password',
        'stripe_id',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    protected $appends = ['roles_array', 'total_bookings'];

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
        return $this->belongsToMany(Review::class, 'helpful_review', 'user_id', 'review_id')->withTimestamps(); // If you create a pivot model
    }

    public function loyaltyPoints()
    {
        return $this->hasMany(LoyaltyPoint::class);
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
