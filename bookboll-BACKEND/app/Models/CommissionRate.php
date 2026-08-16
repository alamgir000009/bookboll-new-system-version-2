<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionRate extends Model
{
    protected $fillable = [
        'country_id',
        'listing_type',
        'rate_percent',
    ];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'float',
        ];
    }
}
