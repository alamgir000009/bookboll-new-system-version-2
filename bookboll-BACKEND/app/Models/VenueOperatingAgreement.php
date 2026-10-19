<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable version of what Bookboll owes a venue's landlord.
 *
 * Never edited after it is written. A change is a new version that supersedes
 * this one, because rent and revenue share are posted against service dates and
 * editing an old row would restate months already reported.
 */
class VenueOperatingAgreement extends Model
{
    public const DEAL_FIXED_RENT    = 'fixed_rent';
    public const DEAL_REVENUE_SHARE = 'revenue_share';
    public const DEAL_DIRECT        = 'direct_operation';

    public const DEAL_TYPES = [self::DEAL_FIXED_RENT, self::DEAL_REVENUE_SHARE, self::DEAL_DIRECT];
    public const CHANGE_TYPES = ['initial', 'renewal', 'amendment'];

    protected $fillable = [
        'venue_id', 'version', 'deal_type', 'currency',
        'landlord_id', 'landlord_name', 'monthly_rent_minor', 'bookboll_share_percent',
        'effective_from', 'effective_to', 'superseded_on',
        'revenue_basis', 'rent_proration',
        'change_type', 'change_reason', 'supersedes_agreement_id',
        'contract_document_id', 'notes', 'created_by', 'idempotency_key',
    ];

    protected $casts = [
        'effective_from'     => 'date',
        'effective_to'       => 'date',
        'superseded_on'      => 'date',
        'version'            => 'integer',
        'monthly_rent_minor' => 'integer',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * The last day this agreement actually governed.
     *
     * The contract's own end date unless a successor cut it short first —
     * whichever comes first. Null means it is still running, with no successor
     * and no contracted end.
     */
    public function activeUntil(): ?string
    {
        $contracted = $this->effective_to?->toDateString();
        $superseded = $this->superseded_on?->toDateString();

        if ($contracted && $superseded) {
            return min($contracted, $superseded);
        }

        return $contracted ?: $superseded;
    }

    /** Was this agreement in force on a given date, in the venue's own days? */
    public function governsOn(string $date): bool
    {
        if ($date < $this->effective_from->toDateString()) {
            return false;
        }

        $until = $this->activeUntil();

        return $until === null || $date <= $until;
    }
}
