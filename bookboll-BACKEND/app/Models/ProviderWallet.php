<?php

namespace App\Models;


use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;

class ProviderWallet extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'provider_wallets';

    protected $fillable = [
        'uuid',
        'owner_user_id',
        'available_balance',
        'pending_balance',
        'lifetime_earnings',
        'lifetime_withdrawn',
        'currency',
        'hold_days',
        'setup_method',
        'setup_status',
        'setup_completed_at',
        'country_id',
        'payout_bank_country',
        'payout_bank_name',
        'payout_account_holder',
        'payout_iban',
        'payout_account_number',
        'payout_routing_number',
        'payout_swift',
        'payout_account_type',
        'is_current',
        'status',
        'purpose',
        'kyc_business_name',
        'kyc_registration_no',
        'kyc_address',
        'kyc_contact',
        'kyc_doc_url',
        'kyc_submitted_at',
        'kyc_reviewed_at',
        'kyc_reviewed_by',
        'kyc_rejection_reason',
        'payout_paused',
        'payout_paused_at',
        'payout_pause_reason',
    ];

    protected $casts = [
        'available_balance'   => 'decimal:2',
        'pending_balance'     => 'decimal:2',
        'lifetime_earnings'   => 'decimal:2',
        'lifetime_withdrawn'  => 'decimal:2',
        'hold_days'           => 'integer',
        'is_current'          => 'boolean',
        'payout_paused'       => 'boolean',
        'setup_completed_at'  => 'datetime',
        'kyc_submitted_at'    => 'datetime',
        'kyc_reviewed_at'     => 'datetime',
        'payout_paused_at'    => 'datetime',
    ];

    protected function activityLogName(): string
    {
        return 'provider_wallet';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'uuid',
            'owner_user_id',
            'available_balance',
            'pending_balance',
            'lifetime_earnings',
            'lifetime_withdrawn',
            'currency',
            'hold_days',
            'setup_method',
            'setup_status',
            'setup_completed_at',
            'country_id',
            'payout_bank_country',
            'payout_bank_name',
            'payout_account_holder',
            'payout_iban',
            'payout_account_number',
            'payout_routing_number',
            'payout_swift',
            'payout_account_type',
            'is_current',
            'status',
            'purpose',
            'kyc_business_name',
            'kyc_registration_no',
            'kyc_address',
            'kyc_contact',
            'kyc_doc_url',
            'kyc_submitted_at',
            'kyc_reviewed_at',
            'kyc_reviewed_by',
            'kyc_rejection_reason',
            'payout_paused',
            'payout_paused_at',
            'payout_pause_reason',
        ];
    }
}
