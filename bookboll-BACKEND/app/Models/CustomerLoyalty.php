<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLoyalty extends Model
{
    protected $table = 'customer_loyalty';

    protected $fillable = [
        'customer_id',
        'country_id',
        'points',
        'lifetime_points',
    ];

    protected function casts(): array
    {
        return [
            'points'           => 'integer',
            'lifetime_points'  => 'integer',
            'country_id'       => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
