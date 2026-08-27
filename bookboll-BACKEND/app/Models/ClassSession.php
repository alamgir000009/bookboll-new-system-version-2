<?php

namespace App\Models;


use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassSession extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'class_sessions';

    protected $fillable = [
        'uuid',
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

    protected function activityLogName(): string
    {
        return 'class_session';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'uuid',
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
    }
}
