<?php

namespace App\Models;


use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * Venue weekly hours (= Postgres `location_hours`).
 *
 * Column mapping:
 *   location_id   → venue_id
 *   day_of_week   → day (monday…sunday string; accessor exposes 0–6)
 *   open_time / close_time → same
 */
class VenueHourPeriod extends Model
{
    use LogsModelActivity;

    protected $table = 'venue_hour_periods';

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
        'venue_id',
        'day',
        'is_open',
        'open_time',
        'close_time',
    ];

    protected $casts = [
        'is_open'    => 'boolean',
        'open_time'  => 'datetime:H:i',
        'close_time' => 'datetime:H:i',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('is_open', true);
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
    public function toDashboardRow(): array
    {
        return [
            'location_id' => (string) $this->venue_id,
            'day_of_week' => $this->day_of_week,
            'is_open'     => (bool) $this->is_open,
            'open_time'   => $this->fmtTime($this->open_time),
            'close_time'  => $this->fmtTime($this->close_time),
        ];
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
        return 'venue_hour_period';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'venue_id',
            'day',
            'is_open',
            'open_time',
            'close_time',
        ];
    }
}
