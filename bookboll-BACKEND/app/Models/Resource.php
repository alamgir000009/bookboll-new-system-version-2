<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class Resource extends Model
{
    use LogsModelActivity;

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