<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'day',
        'is_open',
        'to_time',
        'venue_id',
        'from_time',
        'listing_id',
    ];

    protected $casts = [
        'is_open'   => 'boolean',
        'from_time' => 'datetime:H:i',
        'to_time'   => 'datetime:H:i',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('is_open', true);
    }

    public function scopeForListing($query, $listingId)
    {
        return $query->where('listing_id', $listingId);
    }

    public function isTimeSlotAvailable($time)
    {
        if (! $this->is_open) {
            return false;
        }

        $checkTime = \Carbon\Carbon::createFromTimeString($time);
        $fromTime  = \Carbon\Carbon::createFromTimeString($this->from_time);
        $toTime    = \Carbon\Carbon::createFromTimeString($this->to_time);

        return $checkTime->between($fromTime, $toTime);
    }
}
