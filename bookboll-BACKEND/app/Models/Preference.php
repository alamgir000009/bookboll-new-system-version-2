<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Preference extends Model
{
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
}
