<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;

class OfferRedemption extends Model
{
    use LogsModelActivity;
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

    protected function activityLogName(): string
    {
        return 'offer_redemption';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'uuid',
            'customer_email',
            'owner_user_id',
            'program_id',
            'subscription_id',
            'offer_type',
            'source',
        ];
    }
}
