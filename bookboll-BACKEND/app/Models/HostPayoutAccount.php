<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where an event host's ticket money goes — either a Stripe Express account
 * Bookboll created for them, or their own Stripe account via their own keys.
 */
class HostPayoutAccount extends Model
{
    use LogsModelActivity;
    use GeneratesUuid;

    protected $fillable = [
        'uuid',
        'user_id',
        'mode',
        'status',
        'stripe_account_id',
        'account_name',
        'country',
        'livemode',
        'charges_enabled',
        'payouts_enabled',
        'details_submitted',
        'secret_key',
        'publishable_key',
        'connected_at',
        'synced_at',
    ];

    protected $casts = [
        'livemode'          => 'boolean',
        'charges_enabled'   => 'boolean',
        'payouts_enabled'   => 'boolean',
        'details_submitted' => 'boolean',
        // Never stored in the clear; Laravel encrypts and decrypts it for us.
        'secret_key'        => 'encrypted',
        'connected_at'      => 'datetime',
        'synced_at'         => 'datetime',
    ];

    /** The secret key must never leave the server. */
    protected $hidden = ['secret_key'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Can this host actually sell tickets right now? */
    public function isReady(): bool
    {
        return $this->status === 'active' && $this->charges_enabled;
    }

    protected function activityLogName(): string
    {
        return 'host_payout_account';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'uuid',
            'user_id',
            'mode',
            'status',
            'stripe_account_id',
            'account_name',
            'country',
            'livemode',
            'charges_enabled',
            'payouts_enabled',
            'details_submitted',
            'connected_at',
        ];
    }
}
