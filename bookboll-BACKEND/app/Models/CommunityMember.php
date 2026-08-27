<?php

namespace App\Models;


use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityMember extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'community_members';

    protected $fillable = [
        'uuid',
        'community_id',
        'customer_id',
        'role',
        'status',
        'last_read_at',
        'joined_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'joined_at'    => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CommunityMember $row) {
            if (! $row->joined_at) {
                $row->joined_at = now();
            }
        });
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id');
    }

    protected function activityLogName(): string
    {
        return 'community_member';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'uuid',
            'community_id',
            'customer_id',
            'role',
            'status',
            'last_read_at',
            'joined_at',
            'created_at',
            'updated_at',
        ];
    }
}
