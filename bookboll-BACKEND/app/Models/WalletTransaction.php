<?php

namespace App\Models;


use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class WalletTransaction extends Model
{
    use LogsModelActivity;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'wallet_transactions';

    protected $fillable = [
        'id',
        'owner_user_id',
        'type',
        'direction',
        'amount',
        'provider_share',
        'platform_fee',
        'source_id',
        'source_label',
        'description',
        'status',
        'currency',
        'available_on',
        'hold_days_snapshot',
        'released_at',
        'country_id',
        'wallet_id',
        'created_at',
    ];

    protected $casts = [
        'amount'              => 'decimal:2',
        'provider_share'      => 'decimal:2',
        'platform_fee'        => 'decimal:2',
        'hold_days_snapshot'  => 'integer',
        'available_on'        => 'date',
        'released_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WalletTransaction $row) {
            if (! $row->id) {
                $row->id = (string) Str::uuid();
            }
            if (! $row->created_at) {
                $row->created_at = now();
            }
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(ProviderWallet::class, 'wallet_id');
    }

    protected function activityLogName(): string
    {
        return 'wallet_transaction';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'owner_user_id',
            'type',
            'direction',
            'amount',
            'provider_share',
            'platform_fee',
            'source_id',
            'source_label',
            'description',
            'status',
            'currency',
            'available_on',
            'hold_days_snapshot',
            'released_at',
            'country_id',
            'wallet_id',
            'created_at',
        ];
    }
}
