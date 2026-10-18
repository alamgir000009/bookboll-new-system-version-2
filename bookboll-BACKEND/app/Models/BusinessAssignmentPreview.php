<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What an admin actually reviewed before an account was moved into a business. */
class BusinessAssignmentPreview extends Model
{
    protected $fillable = [
        'token', 'business_id', 'actor_user_id', 'target_user_id',
        'role', 'scope', 'location_ids', 'warnings', 'blockers',
        'state_hash', 'expires_at', 'consumed_at', 'idempotency_key', 'membership_id',
    ];

    protected $casts = [
        'location_ids' => 'array',
        'warnings'     => 'array',
        'blockers'     => 'array',
        'expires_at'   => 'datetime',
        'consumed_at'  => 'datetime',
    ];

    public function isExpired(): bool
    {
        return ! $this->expires_at || $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
