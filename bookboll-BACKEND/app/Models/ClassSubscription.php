<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSubscription extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'class_subscriptions';

    protected $fillable = [
        'id',
        'program_id',
        'customer_id',
        'customer_name',
        'customer_email',
        'started_at',
        'next_billing_at',
        'trial_ends_at',
        'status',
        'trial_status',
        'billing_interval',
        'monthly_amount',
        'provider_payout',
        'platform_fee',
        'joining_fee',
        'card_on_file_id',
        'first_charge_at',
        'cancelled_during_trial_at',
        'cancelled_at',
        'paused_until',
        'payment_failed_at',
        'country_id',
        'wallet_id',
        'form_answers',
        'is_comp',
        'comp_reason',
        'cancel_reason',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'started_at'                => 'date',
        'next_billing_at'           => 'date',
        'trial_ends_at'             => 'date',
        'monthly_amount'            => 'decimal:2',
        'provider_payout'           => 'decimal:2',
        'platform_fee'              => 'decimal:2',
        'first_charge_at'           => 'datetime',
        'cancelled_during_trial_at' => 'datetime',
        'cancelled_at'               => 'datetime',
        'paused_until'              => 'datetime',
        'payment_failed_at'         => 'datetime',
        'form_answers'              => 'array',
        'is_comp'                   => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(ClassProgram::class, 'program_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class, 'subscription_id');
    }
}
