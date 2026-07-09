<?php

namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnesignalSubscription extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'user_id',
        'onesignal_id',
        'device_type',
        'device_info',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    protected function activityLogName(): string
    {
        return 'onesignal_subscription';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'user_id',
            'onesignal_id',
            'device_type',
            'device_info',
            'is_active',
        ];
    }

}