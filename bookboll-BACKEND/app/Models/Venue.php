<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    use LogsModelActivity;

    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'status',
        'maps_url',
        'sort_order',
        'description',
        'min_advance_booking',
        'max_bookings_per_slot',
    ];

    public function scopeActive($query)
    {
        return $query->where("status", self::ACTIVE);
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
            'maps_url',
            'sort_order',
            'description',
            'min_advance_booking',
            'max_bookings_per_slot',
        ];
    }

}