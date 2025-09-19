<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftCard extends Model
{
    protected $fillable = [
        'title',
        'value',
        'status',
        'currency',
        'description',
        'points_required',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
