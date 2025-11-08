<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'color',
        'order',
        'is_active',
        'min_points',
        'max_points',
        'description',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    public function getNextTier()
    {
        return self::active()->ordered()->where('order', '>', $this->order)->first();
    }

    public function qualifiesFor(int $points): bool
    {
        $max = $this->max_points === 2147483647 ? PHP_INT_MAX : $this->max_points;
        return $points >= $this->min_points && $points <= $max;
    }
}
