<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'email',
        'name',
        'status',
    ];

    protected $casts = [
        'subscribed_at' => 'datetime',
    ];
    protected function activityLogName(): string
    {
        return 'subscriber';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'email',
            'name',
            'status',
        ];
    }

}