<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One thing Meta sent us — an inbound message, or a delivery receipt. */
class WhatsappInbound extends Model
{
    protected $table = 'whatsapp_inbound';

    protected $fillable = [
        'kind', 'wa_message_id', 'from_number', 'from_name',
        'phone_number_id', 'message_type', 'body', 'status', 'raw', 'received_at',
    ];

    protected function casts(): array
    {
        return ['raw' => 'array', 'received_at' => 'datetime'];
    }
}
