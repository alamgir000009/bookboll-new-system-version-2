<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    protected $fillable = [
        'name',
        'status',
        'unit_type',
        'total_quantity',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
