<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class RegistrationRequest extends Model
{
    use LogsModelActivity, HasFactory, TracksCreator;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $fillable = [
        'created_by',
        'name',
        'email',
        'phone',
        'password',
        'status',
        'rejection_reason',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }
    protected function activityLogName(): string
    {
        return 'registration_request';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'email',
            'phone',
            'status',
            'rejection_reason',
        ];
    }

}