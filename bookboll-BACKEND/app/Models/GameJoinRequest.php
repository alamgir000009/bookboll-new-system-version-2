<?php

namespace App\Models;


use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GameJoinRequest extends Model
{
    use LogsModelActivity;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'game_join_requests';

    protected $fillable = [
        'id',
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
    ];

    protected $casts = [
        'form_answers' => 'array',
        'attended'     => 'boolean',
        'created_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (GameJoinRequest $row) {
            if (! $row->id) {
                $row->id = (string) Str::uuid();
            }
            if (! $row->created_at) {
                $row->created_at = now();
            }
        });
    }

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
        ];
    }
}
