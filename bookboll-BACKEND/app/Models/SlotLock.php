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
        'date'         => 'date',
        'locked_until' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('locked_until', '>', now());
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