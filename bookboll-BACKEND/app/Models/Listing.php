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
        'show_on_admin',
        'show_on_customer',
        'capacity',
        'amenities',
        'tags',
        'sort_order',
        'base_price',
        'images_urls',
        'description',
        'slot_duration',
        'online_payment',
        'min_advance_minutes',
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
        'show_on_admin'           => 'boolean',
        'show_on_customer'        => 'boolean',
        'buffer_time'             => 'integer',
        'booking_hold_minutes'    => 'integer',
        'extension_max_slots'     => 'integer',
        'submitted_at'            => 'datetime',
        'min_advance_minutes'    => 'integer',
    ];

    protected $appends = ['location_id', 'booking_color', 'visibility'];

    public const APPROVAL_PENDING  = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    public const ELIGIBILITY_ELIGIBLE   = 'eligible';
    public const ELIGIBILITY_PAUSED     = 'paused';
    public const ELIGIBILITY_INELIGIBLE = 'ineligible';

    /**
     * Where a listing may be seen. `show_on_marketplace` / `show_on_provider_site`
     * decide WHICH storefront; these two decide whether it is bookable by staff in
     * the admin, by customers, or both.
     */
    public const VISIBILITY_BOTH          = 'both';
    public const VISIBILITY_ADMIN_ONLY    = 'admin_only';
    public const VISIBILITY_CUSTOMER_ONLY = 'customer_only';
    public const VISIBILITY_INACTIVE      = 'inactive';

    /**
     * `status` is derived from the two visibility flags so that everything still
     * reading `status` keeps working: a listing hidden on both sides is inactive.
     *
     * A caller that only knows the old `status` field (seeders, imports) has its
     * flags seeded from that status, so it keeps behaving as before.
     */
    protected static function booted(): void
    {
        static::saving(function (self $listing) {
            $fallback = ($listing->attributes['status'] ?? 'active') === 'active';

            foreach (['show_on_admin', 'show_on_customer'] as $flag) {
                if (! array_key_exists($flag, $listing->attributes)) {
                    $listing->{$flag} = $fallback;
                }
            }

            $listing->status = ($listing->show_on_admin || $listing->show_on_customer)
                ? 'active'
                : 'inactive';
        });
    }

    /**
     * One of: both, admin_only, customer_only, inactive. Null when the query
     * did not select the visibility columns.
     */
    public function getVisibilityAttribute(): ?string
    {
        if (! array_key_exists('show_on_admin', $this->attributes)
            && ! array_key_exists('show_on_customer', $this->attributes)) {
            return null;
        }

        $admin    = (bool) ($this->attributes['show_on_admin'] ?? false);
        $customer = (bool) ($this->attributes['show_on_customer'] ?? false);

        return match (true) {
            $admin && $customer => self::VISIBILITY_BOTH,
            $admin              => self::VISIBILITY_ADMIN_ONLY,
            $customer           => self::VISIBILITY_CUSTOMER_ONLY,
            default             => self::VISIBILITY_INACTIVE,
        };
    }

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
        // The column is a float documented "In hours" (create_listings_table),
        // and the legacy admin still writes hours into it while the dashboard
        // writes minutes — so anything up to 12 is read as hours. Kept as a
        // float until the last step: a legacy half-hour slot is stored as 0.5,
        // and casting to int first collapsed it to 1 minute.
        $n = (float) ($this->slot_duration ?: 60);
        if ($n > 0 && $n <= 12) {
            return (int) round($n * 60);
        }

        return max(1, (int) round($n));
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

    /**
     * Bookable from the admin console and the admin mobile app.
     */
    public function scopeVisibleToAdmins($query)
    {
        return $query->where('show_on_admin', true);
    }

    /**
     * Bookable from the customer website and the customer mobile app.
     */
    public function scopeVisibleToCustomers($query)
    {
        return $query->where('show_on_customer', true);
    }

    /**
     * Visibility for whichever surface the current request came from.
     */
    public function scopeVisibleToRequestSurface($query)
    {
        return isRequestFromAdminSurface()
            ? $query->visibleToAdmins()
            : $query->visibleToCustomers();
    }

    /**
     * Whether this listing may be booked from the current request's surface.
     */
    public function isVisibleToRequestSurface(): bool
    {
        return isRequestFromAdminSurface()
            ? (bool) $this->show_on_admin
            : (bool) $this->show_on_customer;
    }

    /**
     * Minimum lead time before a slot may be booked, in minutes. Zero means a
     * slot can be booked right up to its start.
     *
     * Always zero on a staff surface: the admin console and admin mobile app
     * take walk-ins and phone bookings for slots starting within minutes.
     */
    public function minAdvanceMinutes(): int
    {
        if (isRequestFromAdminSurface()) {
            return 0;
        }

        return max(0, (int) $this->min_advance_minutes);
    }

    /**
     * The earliest moment a slot may start, or null when no lead time applies.
     */
    public function minAdvanceCutoff(): ?Carbon
    {
        $minutes = $this->minAdvanceMinutes();

        return $minutes > 0 ? Carbon::now()->addMinutes($minutes) : null;
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
            'show_on_admin',
            'show_on_customer',
            'tags',
            'sort_order',
            'base_price',
            'images_urls',
            'photo_url',
            'description',
            'slot_duration',
            'buffer_time',
            'online_payment',
            'min_advance_minutes',
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
