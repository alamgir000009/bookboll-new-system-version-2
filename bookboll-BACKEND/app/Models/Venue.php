<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'status',
        'maps_url',
        'description',
        'min_advance_booking',
        'max_bookings_per_slot',
    ];

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
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
