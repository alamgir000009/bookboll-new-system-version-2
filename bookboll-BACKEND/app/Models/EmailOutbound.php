<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One dispatched email. Server-written only — see MarketingMailService. */
class EmailOutbound extends Model
{
    protected $table = 'email_outbound';

    protected $fillable = [
        'owner_user_id', 'to_email', 'customer_id', 'campaign_id',
        'kind', 'subject', 'status', 'error',
    ];
}
