<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    use LogsModelActivity, TracksCreator;

    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';

    public const STATUSES = [
        self::ACTIVE,
        self::INACTIVE,
    ];

    protected $fillable = [
        'name',
        'status',
        'unit_type',
        'created_by',
        'total_quantity',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', self::ACTIVE);
    }
    protected function activityLogName(): string
    {
        return 'resource';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'status',
            'unit_type',
            'total_quantity',
        ];
    }
}
