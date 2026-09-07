<?php

namespace App\Models;

use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider's bank account for online payments (Postgres: payment_accounts).
 * Superseded by provider_wallets for payouts, but still surfaced on Listings.
 */
class PaymentAccount extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $fillable = [
        'uuid', 'owner_user_id', 'account_holder', 'bank_name',
        'iban', 'swift', 'currency', 'is_primary', 'verified',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'verified'   => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    protected function activityLogName(): string
    {
        return 'payment_account';
    }

    protected function activityLogAttributes(): array
    {
        return ['owner_user_id', 'account_holder', 'bank_name', 'currency', 'is_primary', 'verified'];
    }
}
