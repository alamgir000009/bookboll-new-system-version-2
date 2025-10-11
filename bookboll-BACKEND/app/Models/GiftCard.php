<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftCard extends Model
{
    protected $fillable = [
        'image',
        'title',
        'value',
        'status',
        'currency',
        'description',
        'points_required',
    ];

    public function getImageAttribute($image)
    {
        return $image ? url("storage/{$image}") : null;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
