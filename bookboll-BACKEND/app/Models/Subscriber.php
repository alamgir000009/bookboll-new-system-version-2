<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    protected $fillable = [
        'email',
        'name',
        'status',
    ];

    protected $casts = [
        'subscribed_at' => 'datetime',
    ];
}
