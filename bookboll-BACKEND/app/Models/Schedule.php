<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    use LogsModelActivity, HasFactory, TracksCreator;

    protected $fillable = [
        'created_by',
        'day',
        'is_open',
        'end_time',
        'venue_id',
        'start_time',
        'listing_id',
    ];

    protected $casts = [
        'is_open'    => 'boolean',
        'start_time' => 'datetime:H:i',
        'end_time'   => 'datetime:H:i',
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

        $checkTime = Carbon::createFromTimeString($time);
        $startTime = Carbon::createFromTimeString($this->start_time);
        $endTime   = Carbon::createFromTimeString($this->end_time);

        return $checkTime->between($startTime, $endTime);
    }
    protected function activityLogName(): string
    {
        return 'schedule';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'day',
            'is_open',
            'end_time',
            'venue_id',
            'start_time',
            'listing_id',
        ];
    }

}