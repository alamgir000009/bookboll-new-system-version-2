<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'notes',
        'status',
        'user_id',
        'venue_id',
        'end_time',
        'subtotal',
        'start_time',
        'listing_id',
        'total_price',
        'promocode_id',
        'payment_method',
        'discount_amount',
    ];

    protected $casts = [
        'start_time'      => 'datetime',
        'end_time'        => 'datetime',
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_price'     => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function promocode()
    {
        return $this->belongsTo(Promocode::class);
    }

    public function addOns()
    {
        return $this->belongsToMany(AddOn::class);
    }

    public function review()
    {
        return $this->hasOne(Review::class);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'confirmed']);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('start_time', $date);
    }

    public function calculateDuration()
    {
        return $this->start_time->diffInHours($this->end_time);
    }

    public function isAvailable()
    {
        $overlapping = self::where('listing_id', $this->listing_id)
            ->where('id', '!=', $this->id)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) {
                $q->whereBetween('start_time', [$this->start_time, $this->end_time])
                    ->orWhereBetween('end_time', [$this->start_time, $this->end_time])
                    ->orWhere(function ($q) {
                        $q->where('start_time', '<=', $this->start_time)
                            ->where('end_time', '>=', $this->end_time);
                    });
            })->exists();

        if ($overlapping) {
            return false;
        }

        $listingSchedule = $this->listing->schedules()->where('day', strtolower($this->start_time->format('l')))->first();
        if (! $listingSchedule || ! $listingSchedule->is_open) {
            return false;
        }

        $startHour = $this->start_time->format('H:i');
        $endHour   = $this->end_time->format('H:i');
        if ($startHour < $listingSchedule->from_time || $endHour > $listingSchedule->to_time) {
            return false;
        }

        return true;
    }

    public function getFormattedStartTimeAttribute()
    {
        return $this->start_time->format('m/d/Y h:i A');
    }

    public function getFormattedEndTimeAttribute()
    {
        return $this->end_time->format('m/d/Y h:i A');
    }
}
