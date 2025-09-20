<?php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'status',
        'venue_id',
        'capacity',
        'base_price',
        'image_urls',
        'sports_type',
        'description',
        'slot_duration',
        'loyalty_points',
        'calendar_color',
        'payment_options',
        'max_bookings_per_slot',
    ];

    protected $casts = [
        'base_price'      => 'decimal:2',
        'payment_options' => 'array',
        'image_urls'      => 'array',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
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

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'listing_id');
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

        foreach ($days as $daySchedules) {
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
        $start1 = Carbon::createFromTimeString($schedule1['from_time']);
        $end1   = Carbon::createFromTimeString($schedule1['to_time']);
        $start2 = Carbon::createFromTimeString($schedule2['from_time']);
        $end2   = Carbon::createFromTimeString($schedule2['to_time']);

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

        $start1 = Carbon::createFromTimeString($group1['start_time']);
        $end1   = Carbon::createFromTimeString($group1['end_time']);
        $start2 = Carbon::createFromTimeString($group2['start_time']);
        $end2   = Carbon::createFromTimeString($group2['end_time']);

        return $start1->lt($end2) && $end1->gt($start2);
    }
}
