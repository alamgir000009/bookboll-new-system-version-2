<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class Preference extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'user_id',
        'language',
        'currency',
        'notifications',
        'favorite_sports',
    ];

    public function casts(): array
    {
        return [
            'notifications'   => 'array',
            'favorite_sports' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function hasNotification($key)
    {
        return $this->notifications[$key] ?? false;
    }
    protected function activityLogName(): string
    {
        return 'preference';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'user_id',
            'language',
            'currency',
            'notifications',
            'favorite_sports',
        ];
    }

}