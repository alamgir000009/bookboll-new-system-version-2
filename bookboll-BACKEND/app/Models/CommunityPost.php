<?php

namespace App\Models;


use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityPost extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'community_posts';

    protected $fillable = [
        'uuid',
        'community_id',
        'author_id',
        'body',
        'photos',
        'moderation_status',
        'moderation_reason',
        'moderated_at',
        'moderated_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'photos'       => 'array',
        'moderated_at' => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id');
    }

    protected function activityLogName(): string
    {
        return 'community_post';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'uuid',
            'community_id',
            'author_id',
            'body',
            'photos',
            'moderation_status',
            'moderation_reason',
            'moderated_at',
            'moderated_by',
            'created_at',
            'updated_at',
        ];
    }
}
