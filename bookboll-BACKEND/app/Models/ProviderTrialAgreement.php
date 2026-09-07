<?php

namespace App\Models;

use App\Traits\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider's acceptance of the free-trial terms for one programme, stamped
 * with the commission and trial length in force at that moment.
 */
class ProviderTrialAgreement extends Model
{
    use GeneratesUuid;

    protected $fillable = [
        'uuid', 'program_id', 'owner_user_id', 'terms_version',
        'commission_at_acceptance', 'trial_days_at_acceptance',
    ];

    protected $casts = [
        'commission_at_acceptance' => 'decimal:2',
        'trial_days_at_acceptance' => 'integer',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(ClassProgram::class, 'program_id');
    }
}
