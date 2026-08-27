<?php

namespace App\Models;


use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Community extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'communities';

    protected $fillable = [
        'uuid',
        'owner_customer_id',
        'name',
        'description',
        'sport',
        'sports',
        'city_id',
        'country_id',
        'cover_image',
        'images',
        'is_private',
        'settings',
        'moderation_status',
        'moderation_reason',
        'moderated_at',
        'moderated_by',
        'avatar_image',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'sports'            => 'array',
        'images'            => 'array',
        'is_private'        => 'boolean',
        'settings'          => 'array',
        'moderated_at'      => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(CommunityMember::class, 'community_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(CommunityPost::class, 'community_id');
    }

    protected function activityLogName(): string
    {
        return 'community';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'uuid',
            'owner_customer_id',
            'name',
            'description',
            'sport',
            'sports',
            'city_id',
            'country_id',
            'cover_image',
            'images',
            'is_private',
            'settings',
            'moderation_status',
            'moderation_reason',
            'moderated_at',
            'moderated_by',
            'avatar_image',
            'created_at',
            'updated_at',
        ];
    }
}
