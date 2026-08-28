<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Listing extends Model
{
    use LogsModelActivity, HasFactory, TracksCreator;

    protected $fillable = [
        'created_by',
        'name',
        'status',
        'venue_id',
        'capacity',
        'amenities',
        'tags',
        'sort_order',
        'base_price',
        'images_urls',
        'description',
        'slot_duration',
        'online_payment',
        'loyalty_points',
        'calendar_color',
        'payment_options',
        'sports_category_id',
        'sport_category',
        'max_bookings_per_slot',
        'max_multiple_bookings',
        'allow_multiple_bookings',

        // Postgres / dashboard locations→listings parity
        'photo_url',
        'photos',
        'approval_status',
        'eligibility_status',
        'admin_note',
        'previous_snapshot',
        'pending_content',
        'listing_type',
        'category',
        'show_on_marketplace',
        'show_on_provider_site',
        'buffer_time',
        'start_time_mode',
        'fixed_start_times',
        'booking_hold_minutes',
        'extension_pricing',
        'extension_value',
        'extension_max_slots',
        'commission_rate',
        'submitted_at',
        'submitted_by_name',
    ];

    protected $casts = [
        'payment_options'         => 'array',
        'images_urls'             => 'array',
        'amenities'               => 'array',
        'tags'                    => 'array',
        'photos'                  => 'array',
        'previous_snapshot'       => 'array',
        'pending_content'         => 'array',
        'fixed_start_times'       => 'array',
        'base_price'              => 'decimal:2',
        'extension_value'         => 'decimal:2',
        'commission_rate'         => 'decimal:2',
        'allow_multiple_bookings' => 'boolean',
        'online_payment'          => 'boolean',
        'show_on_marketplace'     => 'boolean',
        'show_on_provider_site'   => 'boolean',
        'buffer_time'             => 'integer',
        'booking_hold_minutes'    => 'integer',
        'extension_max_slots'     => 'integer',
        'submitted_at'            => 'datetime',
    ];

    protected $appends = ['location_id', 'booking_color'];

    public const APPROVAL_PENDING  = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    public const ELIGIBILITY_ELIGIBLE   = 'eligible';
    public const ELIGIBILITY_PAUSED     = 'paused';
    public const ELIGIBILITY_INELIGIBLE = 'ineligible';

    public function sportsCategory()
    {
        return $this->belongsTo(SportsCategory::class);
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    /** Alias for dashboard UI that still speaks `location_id` (Postgres). */
    public function getLocationIdAttribute()
    {
        return $this->attributes['venue_id'] ?? null;
    }

    public function setLocationIdAttribute($value): void
    {
        $this->attributes['venue_id'] = $value;
    }

    /** Alias for Postgres `booking_color` → calendar_color. */
    public function getBookingColorAttribute(): ?string
    {
        return $this->attributes['calendar_color'] ?? null;
    }

    public function setBookingColorAttribute(?string $value): void
    {
        $this->attributes['calendar_color'] = $value;
    }

    public function scopeApproved($query)
    {
        return $query->where('approval_status', self::APPROVAL_APPROVED);
    }

    public function pricingGroups()
    {
        return $this->hasMany(PricingGroup::class);
    }

    public function pricingCells()
    {
        return $this->hasMany(PricingCell::class);
    }

    public function addOns()
    {
        return $this->belongsToMany(AddOn::class);
    }

    public function resources()
    {
        return $this->belongsToMany(Resource::class)->withPivot('quantity');
    }

    public function resourceRequirements()
    {
        return $this->belongsToMany(Resource::class, 'listing_resource', 'listing_id', 'resource_id')->withPivot('quantity');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'listing_id');
    }

    /**
     * Slot length in minutes.
     * Legacy rows store hours (1–12); admin dashboard stores minutes (15/30/45/60/90/120).
     */
    public function slotDurationMinutes(): int
    {
        $n = (int) ($this->slot_duration ?: 60);
        if ($n > 0 && $n <= 12) {
            return $n * 60;
        }

        return max(1, $n);
    }

    public function reviews()
    {
        return Review::whereRelation('booking', 'listing_id', $this->id);
    }

    public function assets()
    {
        return $this->morphMany(Asset::class, 'assetable')->orderBy('sort_order')->orderBy('id');
    }

    public function getImagesUrlsAttribute($images)
    {
        if ($this->relationLoaded('assets') || $this->assets()->exists()) {
            return $this->assets->map(function ($asset) {
                return $asset->url;
            })->toArray();
        }

        $images = $this->attributes['images_urls'] ?? null;

        if (! empty($images)) {
            $images = is_array($images) ? $images : json_decode($images, true);

            $newImages = [];
            foreach ($images as $image) {
                if (strpos($image, 'http') === 0 || strpos($image, '/storage/') !== false) {
                    $newImages[] = $image;
                } else {
                    $newImages[] = url("storage/{$image}");
                }
            }

            return $newImages;
        }

        return [];
    }

    public function getAverageRatingAttribute()
    {
        return $this->reviews()->avg('overall_rating') ?? 0;
    }

    public function getTotalReviewsAttribute()
    {
        return $this->reviews()->count();
    }

    public function getStarDistributionAttribute()
    {
        return $this->reviews()->selectRaw('overall_rating, COUNT(*) as count')
            ->groupBy('overall_rating')
            ->pluck('count', 'overall_rating')
            ->toArray();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getOpenDaysAttribute()
    {
        return $this->schedules->where('is_open', true)->pluck('day');
    }

    public function isOpenOnDay($day)
    {
        return $this->schedules->where('day', $day)->where('is_open', true)->isNotEmpty();
    }

    public function getScheduleForDay($day)
    {
        return $this->schedules->where('day', $day)->where('is_open', true)->first();
    }

    public function validateScheduleConflicts(array $scheduleData)
    {
        $days = collect($scheduleData)->groupBy('day');

        foreach ($days as $day => $daySchedules) {
            if ($daySchedules->count() > 1) {
                foreach ($daySchedules as $i => $schedule) {
                    foreach ($daySchedules as $j => $otherSchedule) {
                        if ($i !== $j && $this->timesOverlap($schedule, $otherSchedule)) {
                            throw new \Exception("Schedule conflict detected on {$day}: times overlap.");
                        }
                    }
                }
            }
        }
    }

    protected function timesOverlap($schedule1, $schedule2)
    {
        $start1 = Carbon::createFromTimeString($schedule1['start_time']);
        $end1   = Carbon::createFromTimeString($schedule1['end_time']);
        $start2 = Carbon::createFromTimeString($schedule2['start_time']);
        $end2   = Carbon::createFromTimeString($schedule2['end_time']);

        return ($start1->lt($end2) && $end1->gt($start2));
    }

    public function validatePricingGroups(array $pricingGroups)
    {
        $openDays = $this->getOpenDaysAttribute();

        foreach ($pricingGroups as $group) {
            $groupDays   = collect($group['days']);
            $invalidDays = $groupDays->reject(fn($day) => $openDays->contains($day));

            if ($invalidDays->isNotEmpty()) {
                throw new \Exception("Pricing group '{$group['name']}' includes days not available in schedule: " . $invalidDays->implode(', '));
            }

            $this->validatePricingGroupOverlaps($group, $pricingGroups);
        }
    }

    protected function validatePricingGroupOverlaps($currentGroup, $allGroups)
    {
        foreach ($allGroups as $i => $group) {
            if ($currentGroup !== $group && $this->pricingGroupsOverlap($currentGroup, $group)) {
                throw new \Exception("Pricing groups '{$currentGroup['name']}' and '{$group['name']}' overlap on shared days.");
            }
        }
    }

    protected function pricingGroupsOverlap($group1, $group2)
    {
        $sharedDays = collect($group1['days'])->intersect($group2['days']);

        if ($sharedDays->isEmpty()) {
            return false;
        }

        $end1Time = $group1['end_time'] === '24:00' ? '23:59:59' : $group1['end_time'];
        $end2Time = $group2['end_time'] === '24:00' ? '23:59:59' : $group2['end_time'];

        try {
            $start1 = Carbon::createFromTimeString($group1['start_time']);
            $end1   = Carbon::createFromTimeString($end1Time);
            $start2 = Carbon::createFromTimeString($group2['start_time']);
            $end2   = Carbon::createFromTimeString($end2Time);

            if (($group1['start_time'] === '00:00' && $group1['end_time'] === '24:00') ||
                ($group2['start_time'] === '00:00' && $group2['end_time'] === '24:00')) {
                return true;
            }

            return $start1->lt($end2) && $end1->gt($start2);
        } catch (\Exception $e) {
            $start1Minutes = $this->timeToMinutesForPricing($group1['start_time']);
            $end1Minutes = $this->timeToMinutesForPricing($group1['end_time']);
            $start2Minutes = $this->timeToMinutesForPricing($group2['start_time']);
            $end2Minutes = $this->timeToMinutesForPricing($group2['end_time']);

            return $start1Minutes < $end2Minutes && $start2Minutes < $end1Minutes;
        }
    }

    protected function timeToMinutesForPricing($time)
    {
        if ($time === '24:00') {
            return 24 * 60;
        }
        if ($time === '00:00') {
            return 0;
        }
        list($hours, $minutes) = explode(':', $time);
        return (int) $hours * 60 + (int) $minutes;
    }
    protected function activityLogName(): string
    {
        return 'listing';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'status',
            'venue_id',
            'capacity',
            'amenities',
            'tags',
            'sort_order',
            'base_price',
            'images_urls',
            'photo_url',
            'description',
            'slot_duration',
            'buffer_time',
            'online_payment',
            'loyalty_points',
            'calendar_color',
            'payment_options',
            'sports_category_id',
            'sport_category',
            'max_bookings_per_slot',
            'max_multiple_bookings',
            'allow_multiple_bookings',
            'approval_status',
            'eligibility_status',
            'listing_type',
            'category',
            'show_on_marketplace',
            'show_on_provider_site',
            'extension_pricing',
            'extension_value',
            'extension_max_slots',
            'commission_rate',
            'submitted_at',
            'submitted_by_name',
        ];
    }

}