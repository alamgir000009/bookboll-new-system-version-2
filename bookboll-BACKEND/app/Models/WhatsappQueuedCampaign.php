<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * A campaign waiting on Meta's template review. See the migration for the why.
 */
class WhatsappQueuedCampaign extends Model
{
    use LogsModelActivity;

    public const STATUS_AWAITING  = 'awaiting_approval';
    public const STATUS_SENT      = 'sent';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'owner_user_id', 'template_name', 'template_language', 'payload',
        'status', 'reject_reason', 'result', 'sent_at', 'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'result'       => 'array',
            'sent_at'      => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function scopeAwaiting($query)
    {
        return $query->where('status', self::STATUS_AWAITING);
    }

    protected function activityLogName(): string
    {
        return 'whatsapp_queued_campaign';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'owner_user_id',
            'template_name',
            'template_language',
            'status',
            'reject_reason',
            'sent_at',
            'dismissed_at',
        ];
    }
}
