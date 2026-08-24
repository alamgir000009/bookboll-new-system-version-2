<?php

namespace App\Models;


use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromocodeRedemption extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'promocode_id',
        'code',
        'customer_email',
        'customer_name',
        'source_type',
        'source_id',
        'order_amount',
        'discount_amount',
        'currency',
        'recipient',
        'owner_user_id',
        'country_id',
        'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'order_amount'    => 'float',
            'discount_amount' => 'float',
            'redeemed_at'     => 'datetime',
        ];
    }

    public function promocode(): BelongsTo
    {
        return $this->belongsTo(Promocode::class);
    }

    protected function activityLogName(): string
    {
        return 'promocode_redemption';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'promocode_id',
            'code',
            'customer_email',
            'customer_name',
            'source_type',
            'source_id',
            'order_amount',
            'discount_amount',
            'currency',
            'recipient',
            'owner_user_id',
            'country_id',
            'redeemed_at',
        ];
    }
}
