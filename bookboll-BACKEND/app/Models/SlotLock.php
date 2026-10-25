<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;

class SlotLock extends Model
{
    use LogsModelActivity;

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
        'date' => 'date',
        'locked_until' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('locked_until', '>', now());
    }

    /**
     * Lapsed holds. These no longer block a booking — every availability check
     * goes through active() — but they stay in the table until slot-locks:clear
     * sweeps them, so the admin list has to be able to tell the two apart.
     */
    public function scopeExpired($query)
    {
        return $query->where('locked_until', '<=', now());
    }

    public function isExpired(): bool
    {
        return $this->locked_until === null || $this->locked_until->isPast();
    }

    /** A guest hold, identified only by the session hash of their IP and agent. */
    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    protected function activityLogName(): string
    {
        return 'slot_lock';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'date',
            'user_id',
            'end_time',
            'listing_id',
            'start_time',
            'session_id',
            'ip_address',
            'locked_until',
        ];
    }
}
