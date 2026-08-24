<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class AddOn extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
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
        if (! $image) {
            return null;
        }
        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }

        return url("storage/{$image}");
    }
    protected function activityLogName(): string
    {
        return 'add_on';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'image',
            'price',
            'status',
            'currency',
            'sort_order',
            'description',
            'hours_extension',
        ];
    }

}