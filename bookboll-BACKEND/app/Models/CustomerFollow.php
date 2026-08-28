<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerFollow extends Model
{
    protected $table = 'customer_follows';

    protected $fillable = [
        'status',
        'follower_uuid',
        'following_uuid',
    ];

    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
}
