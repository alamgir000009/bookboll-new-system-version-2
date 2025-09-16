<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VenueSchedule extends Model
{
    protected $fillable = [
        'venue_id',
        'day',
        'is_open',
        'from_time',
        'to_time',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }
}
