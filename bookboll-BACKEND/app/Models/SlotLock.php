<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlotLock extends Model
{
    protected $fillable = [
        'date',
        'user_id',
        'end_time',
        'listing_id',
        'start_time',
        'session_id',
        'ip_address',
        'locked_until',
    ];

    protected $casts = [
        'date'         => 'date',
        'locked_until' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('locked_until', '>', now());
    }
}
