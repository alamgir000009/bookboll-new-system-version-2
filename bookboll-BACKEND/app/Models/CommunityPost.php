<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CommunityPost extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'community_posts';

    protected $fillable = [
        'id',
        'community_id',
        'author_id',
        'body',
        'photos',
        'moderation_status',
        'moderation_reason',
        'moderated_at',
        'moderated_by',
        'created_at',
    ];

    protected $casts = [
        'photos'       => 'array',
        'moderated_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CommunityPost $row) {
            if (! $row->id) {
                $row->id = (string) Str::uuid();
            }
            if (! $row->created_at) {
                $row->created_at = now();
            }
        });
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id');
    }
}
