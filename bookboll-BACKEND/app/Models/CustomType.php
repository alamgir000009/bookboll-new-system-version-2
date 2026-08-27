<?php

namespace App\Models;


use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;

class CustomType extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'custom_types';

    public const STATUS_PENDING  = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const DOMAIN_VENUE   = 'venue';

    public const DOMAIN_PROGRAM = 'program';

    protected $fillable = [
        'uuid',
        'domain',
        'name',
        'category',
        'status',
        'created_by',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    protected function activityLogName(): string
    {
        return 'custom_type';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'uuid',
            'domain',
            'name',
            'category',
            'status',
            'created_by',
            'approved_at',
        ];
    }
}
