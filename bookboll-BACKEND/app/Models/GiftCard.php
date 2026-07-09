<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class GiftCard extends Model
{
    use LogsModelActivity;

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
    protected function activityLogName(): string
    {
        return 'gift_card';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'image',
            'title',
            'value',
            'status',
            'currency',
            'description',
            'points_required',
        ];
    }

}