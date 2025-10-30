<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AddOn extends Model
{
    protected $fillable = [
        'name',
        'image',
        'price',
        'status',
        'currency',
        'sort_order',
        'description',
        'hours_extension',
    ];

    protected $casts = [
        'hours_extension' => 'integer',
        'price'           => 'decimal:2',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getImageAttribute($image)
    {
        return $image ? url("storage/{$image}") : null;
    }
}
