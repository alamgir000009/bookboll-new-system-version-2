<?php

namespace App\Models;


use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalRequest extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'withdrawal_requests';

    protected $fillable = [
        'uuid',
        'owner_user_id',
        'amount',
        'currency',
        'payout_method',
        'payout_details',
        'status',
        'rejection_reason',
        'requested_at',
        'processed_at',
        'processed_by',
        'approved_at',
        'cancelled_at',
        'notes',
        'country_id',
        'wallet_id',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'approved_at'  => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (WithdrawalRequest $row) {
            if (! $row->requested_at) {
                $row->requested_at = now();
            }
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(ProviderWallet::class, 'wallet_id');
    }

    protected function activityLogName(): string
    {
        return 'withdrawal_request';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'uuid',
            'owner_user_id',
            'amount',
            'currency',
            'payout_method',
            'payout_details',
            'status',
            'rejection_reason',
            'requested_at',
            'processed_at',
            'processed_by',
            'approved_at',
            'cancelled_at',
            'notes',
            'country_id',
            'wallet_id',
        ];
    }
}
