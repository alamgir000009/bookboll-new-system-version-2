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

    public const DAY_INDEX = [
        'sunday'    => 0,
        'monday'    => 1,
        'tuesday'   => 2,
        'wednesday' => 3,
        'thursday'  => 4,
        'friday'    => 5,
        'saturday'  => 6,
    ];

    protected $fillable = [
        'created_by',
        'day',
        'is_open',
        'inherit_from_location',
        'end_time',
        'venue_id',
        'start_time',
        'listing_id',
    ];

    protected $casts = [
        'is_open'                => 'boolean',
        'inherit_from_location'  => 'boolean',
        'start_time'             => 'datetime:H:i',
        'end_time'               => 'datetime:H:i',
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

    /** Venue-level hours only (Postgres location_hours equivalent). */
    public function scopeForVenueHours($query)
    {
        return $query->whereNull('listing_id');
    }

    /** Alias for location_hours.open_time → start_time. */
    public function getOpenTimeAttribute()
    {
        return $this->attributes['start_time'] ?? null;
    }

    public function setOpenTimeAttribute($value): void
    {
        $this->attributes['start_time'] = $value;
    }

    /** Alias for location_hours.close_time → end_time. */
    public function getCloseTimeAttribute()
    {
        return $this->attributes['end_time'] ?? null;
    }

    public function setCloseTimeAttribute($value): void
    {
        $this->attributes['end_time'] = $value;
    }

    /** Postgres-style day_of_week (Sunday = 0 … Saturday = 6). */
    public function getDayOfWeekAttribute(): ?int
    {
        $day = strtolower((string) $this->day);

        return self::DAY_INDEX[$day] ?? null;
    }

    /**
     * Dashboard / calendar shape matching apps/admin location_hours rows.
     *
     * @return array{location_id: string, day_of_week: int|null, is_open: bool, open_time: string|null, close_time: string|null}
     */
    public function toDashboardLocationHour(): array
    {
        return [
            'location_id' => (string) $this->venue_id,
            'day_of_week' => $this->day_of_week,
            'is_open'     => (bool) $this->is_open,
            'open_time'   => $this->fmtTime($this->start_time),
            'close_time'  => $this->fmtTime($this->end_time),
        ];
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

    protected function fmtTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }
        $s = (string) $value;

        return strlen($s) >= 5 ? substr($s, 0, 5) : $s;
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
            'inherit_from_location',
            'end_time',
            'venue_id',
            'start_time',
            'listing_id',
        ];
    }
}
