<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustomType extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'custom_types';

    public const STATUS_PENDING  = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const DOMAIN_VENUE   = 'venue';

    public const DOMAIN_PROGRAM = 'program';

    protected $fillable = [
        'id',
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

    protected static function booted(): void
    {
        static::creating(function (CustomType $row) {
            if (! $row->id) {
                $row->id = (string) Str::uuid();
            }
        });
    }
}
