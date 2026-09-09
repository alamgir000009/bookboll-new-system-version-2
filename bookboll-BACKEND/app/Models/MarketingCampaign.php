<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded marketing send — the compliance ledger behind the Customers composer.
 */
class MarketingCampaign extends Model
{
    protected $fillable = [
        'owner_user_id', 'created_by', 'sender_label', 'channel',
        'audience', 'subject', 'body', 'html', 'recipient_count',
        'delivery_status', 'sent_count', 'failed_count', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'recipient_count' => 'integer',
            'sent_count'      => 'integer',
            'failed_count'    => 'integer',
            'completed_at'    => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function messages()
    {
        return $this->hasMany(WhatsappOutbound::class, 'campaign_id');
    }

    public function emails()
    {
        return $this->hasMany(EmailOutbound::class, 'campaign_id');
    }
}
