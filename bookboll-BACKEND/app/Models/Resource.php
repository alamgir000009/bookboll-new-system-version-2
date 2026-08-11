<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'name',
        'status',
        'unit_type',
        'total_quantity',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
    protected function activityLogName(): string
    {
        return 'resource';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'status',
            'unit_type',
            'total_quantity',
        ];
    }

}