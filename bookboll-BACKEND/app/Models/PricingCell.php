<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingCell extends Model
{
    protected $fillable = [
        'listing_id',
        'day_of_week',
        'slot_time',
        'pricing_group_id',
        'direct_price',
    ];

    protected $casts = [
        'day_of_week'  => 'integer',
        'direct_price' => 'decimal:2',
    ];

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function pricingGroup()
    {
        return $this->belongsTo(PricingGroup::class);
    }
}
