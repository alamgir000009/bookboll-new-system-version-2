<?php
namespace App\Models;

use App\Models\Scopes\VenueAccessScope;
use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';

    protected static function booted()
    {
        // Apply global venue access scope
        static::addGlobalScope(new VenueAccessScope);
    }

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
}
