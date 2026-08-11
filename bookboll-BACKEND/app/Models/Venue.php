<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;
use Illuminate\Database\Eloquent\Model;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

class Venue extends Model
{
    use LogsModelActivity, TracksCreator;

    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'status',
        'city_id',
        'state_id',
        'maps_url',
        'country_id',
        'sort_order',
        'created_by',
        'description',
        'min_advance_booking',
        'max_bookings_per_slot',
    ];

    protected $appends = ['location_label'];

    public function scopeActive($query)
    {
        return $query->where("status", self::ACTIVE);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function getLocationLabelAttribute(): ?string
    {
        $parts = array_filter([
            $this->city?->name,
            $this->state?->name,
            $this->country?->name,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class)->whereNull('listing_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function listings()
    {
        return $this->hasMany(Listing::class);
    }

    protected function activityLogName(): string
    {
        return 'venue';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'status',
            'city_id',
            'state_id',
            'maps_url',
            'country_id',
            'sort_order',
            'description',
            'min_advance_booking',
            'max_bookings_per_slot',
        ];
    }
}
