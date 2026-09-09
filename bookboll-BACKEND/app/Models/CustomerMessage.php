<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;

class CustomerMessage extends Model
{
    use LogsModelActivity;
    use GeneratesUuid;

    protected $table = 'customer_messages';

    protected $fillable = [
        'uuid',
        'conversation_key',
        'sender_id',
        'recipient_id',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function activityLogName(): string
    {
        return 'customer_message';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'uuid',
            'conversation_key',
            'sender_id',
            'recipient_id',
            'read_at',
        ];
    }
}
