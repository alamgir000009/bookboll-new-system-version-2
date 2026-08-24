<?php

namespace App\Models;


use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CommunityMember extends Model
{
    use LogsModelActivity;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'community_members';

    protected $fillable = [
        'id',
        'community_id',
        'customer_id',
        'role',
        'status',
        'last_read_at',
        'joined_at',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'joined_at'    => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CommunityMember $row) {
            if (! $row->id) {
                $row->id = (string) Str::uuid();
            }
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
            'community_id',
            'customer_id',
            'role',
            'status',
            'last_read_at',
            'joined_at',
        ];
    }
}
