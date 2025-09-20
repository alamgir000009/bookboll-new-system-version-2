<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AddOn extends Model
{
    protected $fillable = [
        'name',
        'price',
        'status',
        'currency',
        'description',
        'hours_extension',
    ];

    protected $casts = [
        'price'           => 'decimal:2',
        'hours_extension' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
