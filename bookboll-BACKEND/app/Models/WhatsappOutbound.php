<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One dispatched WhatsApp message. Server-written only.
 */
class WhatsappOutbound extends Model
{
    protected $table = 'whatsapp_outbound';

    protected $fillable = [
        'owner_user_id', 'to_number', 'customer_id', 'campaign_id',
        'kind', 'template_name', 'body', 'wa_message_id', 'status', 'error',
    ];
}
