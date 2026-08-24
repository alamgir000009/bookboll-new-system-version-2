<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'class_sessions';

    protected $fillable = [
        'id',
        'subscription_id',
        'program_id',
        'listing_id',
        'session_date',
        'session_time',
        'duration_minutes',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'session_date' => 'date',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ClassSubscription::class, 'subscription_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(ClassProgram::class, 'program_id');
    }
}
