<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingGroup extends Model
{
    protected $fillable = [
        'name',
        'days',
        'end_time',
        'start_time',
        'listing_id',
        'price_per_hour',
    ];

    protected $casts = [
        'price_per_hour' => 'decimal:2',
        'days'           => 'array',
    ];

    public function getStartTimeAttribute($start_time)
    {
        return $start_time ? date('H:i', strtotime($start_time)) : null;
    }

    public function getEndTimeAttribute($end_time)
    {
        return $end_time ? date('H:i', strtotime($end_time)) : null;
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }
}
