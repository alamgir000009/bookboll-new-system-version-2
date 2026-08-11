<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class SportsCategory extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'name',
        'slug',
        'icon',
        'image',
        'status',
        'sort_order',
        'description',
        'sports_type_id',
    ];

    public function sportsType()
    {
        return $this->belongsTo(SportsType::class);
    }

    public function getImageAttribute($image)
    {
        return $image ? url("storage/{$image}") : null;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public static function getPopularEmojis(): array
    {
        return [
            '⚽'  => 'Soccer',
            '🏀'  => 'Basketball',
            '🎾'  => 'Tennis',
            '🏄'  => 'Surfing',
            '🏐'  => 'Volleyball',
            '🏓'  => 'Table Tennis',
            '🥊'  => 'Boxing',
            '🏋️' => 'Weightlifting',
            '🥋'  => 'Karate',
            '🏃'  => 'Running',
            '🚴'  => 'Cycling',
            '🏑'  => 'Hockey',
            '🥍'  => 'Lacrosse',
            '🏸'  => 'Badminton',
            '🎣'  => 'Fishing',
            '⛸️' => 'Ice Skating',
            '🛷'  => 'Sledding',
            '⛳'  => 'Golf',
            '🥅'  => 'Goal Net',
            '🏏'  => 'Cricket',
        ];
    }
    protected function activityLogName(): string
    {
        return 'sports_category';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'slug',
            'icon',
            'image',
            'status',
            'sort_order',
            'description',
            'sports_type_id',
        ];
    }

}