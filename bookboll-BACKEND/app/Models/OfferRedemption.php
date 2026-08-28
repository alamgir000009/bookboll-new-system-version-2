<?php

namespace App\Models;

use App\Traits\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;

class OfferRedemption extends Model
{
    use GeneratesUuid;

    protected $table = 'offer_redemptions';

    protected $fillable = [
        'uuid',
        'customer_email',
        'card_fingerprint',
        'owner_user_id',
        'program_id',
        'subscription_id',
        'offer_type',
        'source',
    ];
}
