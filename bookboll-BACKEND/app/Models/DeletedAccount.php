<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeletedAccount extends Model
{
    public const TYPE_ACCOUNT_DELETED = 'account_deleted';
    public const TYPE_ACCESS_REMOVED = 'access_removed';

    public const SOURCE_ADMIN_MOBILE_APP = 'admin_mobile_app';

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'deletion_type',
        'source',
        'roles',
        'assigned_venues',
        'user_snapshot',
        'ip_address',
        'user_agent',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'roles'            => 'array',
            'assigned_venues'  => 'array',
            'user_snapshot'    => 'array',
            'deleted_at'       => 'datetime',
        ];
    }

    public function scopeFromAdminMobileApp($query)
    {
        return $query->where('source', self::SOURCE_ADMIN_MOBILE_APP);
    }

    public function scopeAccountDeleted($query)
    {
        return $query->where('deletion_type', self::TYPE_ACCOUNT_DELETED);
    }

    public function scopeAccessRemoved($query)
    {
        return $query->where('deletion_type', self::TYPE_ACCESS_REMOVED);
    }
}
