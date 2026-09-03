<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    protected $fillable = [
        'pass_id',
        'program_id',
        'subscription_id',
        'checked_by',
        'note',
        'checked_at',
    ];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function pass(): BelongsTo
    {
        return $this->belongsTo(EntryPass::class, 'pass_id');
    }
}
