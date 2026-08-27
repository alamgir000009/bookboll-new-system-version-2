<?php

namespace App\Models;


use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameJoinRequest extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'game_join_requests';

    protected $fillable = [
        'uuid',
        'game_id',
        'joiner_customer_id',
        'joiner_name',
        'joiner_phone',
        'joiner_whatsapp',
        'joiner_message',
        'status',
        'form_answers',
        'attended',
        'refund_state',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'form_answers' => 'array',
        'attended'     => 'boolean',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(HostedGame::class, 'game_id');
    }

    protected function activityLogName(): string
    {
        return 'game_join_request';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'uuid',
            'game_id',
            'joiner_customer_id',
            'joiner_name',
            'joiner_phone',
            'joiner_whatsapp',
            'joiner_message',
            'status',
            'form_answers',
            'attended',
            'refund_state',
            'created_at',
            'updated_at',
        ];
    }
}
