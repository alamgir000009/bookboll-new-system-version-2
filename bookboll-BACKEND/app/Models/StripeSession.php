<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeSession extends Model
{
    protected $fillable = [
        'session_id',
        'booking_id',
        'booking_ids',
        'source',
        'processed',
    ];

    protected $casts = [
        'processed'   => 'boolean',
        'booking_ids' => 'array',
    ];
}
