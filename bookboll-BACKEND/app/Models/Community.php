<?php

namespace App\Models;


use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Community extends Model
{
    use LogsModelActivity;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'communities';

    protected $fillable = [
        'id',
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
    ];

    protected $casts = [
        'sports'            => 'array',
        'images'            => 'array',
        'is_private'        => 'boolean',
        'settings'          => 'array',
        'moderated_at'      => 'datetime',
        'created_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Community $row) {
            if (! $row->id) {
                $row->id = (string) Str::uuid();
            }
            if (! $row->created_at) {
                $row->created_at = now();
            }
        });
    }

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
        ];
    }
}
