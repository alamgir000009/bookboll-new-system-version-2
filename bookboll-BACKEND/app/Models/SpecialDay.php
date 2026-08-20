<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class SpecialDay extends Model
{
    use LogsModelActivity, TracksCreator;


    protected $fillable = [
        'created_by',
        'date',
        'type',
        'price',
        'price_multiplier',
        'status',
        'venue_id',
        'listing_id',
        'description',
    ];

    protected $casts = [
        'date'             => 'date',
        'price'            => 'decimal:2',
        'price_multiplier' => 'decimal:4',
        'status'           => 'string',
    ];

    public function timeBlocks()
    {
        return $this->hasMany(TimeBlock::class);
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForVenue($query, int $venueId)
    {
        return $query->where('venue_id', $venueId);
    }

    public function scopeForListing($query, ?int $listingId)
    {
        if ($listingId) {
            return $query->where('listing_id', $listingId);
        }
        return $query;
    }

    public function scopeInDateRange($query, Carbon $start, Carbon $end)
    {
        return $query->whereBetween('date', [$start, $end]);
    }

    public static function getCalendarData(int $venueId, ?int $listingId = null, string $yearMonth): array
    {
        $start = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        $specialDays = self::active()->forVenue($venueId)->forListing($listingId)
            ->inDateRange($start, $end)->get()->keyBy('date');

        $calendar = [];
        for ($day = 1; $day <= $end->daysInMonth; $day++) {
            $dateKey        = $start->copy()->day($day)->format('Y-m-d');
            $calendar[$day] = $specialDays->get($dateKey) ?? null;
        }

        return [
            'month'      => $start->format('F Y'),
            'days'       => $calendar,
            'start_day'  => $start->dayOfWeek,
            'total_days' => $end->daysInMonth,
        ];
    }
    protected function activityLogName(): string
    {
        return 'special_day';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'date',
            'type',
            'price',
            'price_multiplier',
            'status',
            'venue_id',
            'listing_id',
            'description',
        ];
    }

}