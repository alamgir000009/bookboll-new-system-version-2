<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class PricingGroup extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'name',
        'days',
        'end_time',
        'start_time',
        'listing_id',
        'price_per_hour',
    ];

    protected $casts = [
        'price_per_hour' => 'decimal:2',
        'days'           => 'array',
    ];

    public function getStartTimeAttribute($start_time)
    {
        return $start_time ? date('H:i', strtotime($start_time)) : null;
    }

    public function getEndTimeAttribute($end_time)
    {
        return $end_time ? date('H:i', strtotime($end_time)) : null;
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }
    protected function activityLogName(): string
    {
        return 'pricing_group';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'days',
            'end_time',
            'start_time',
            'listing_id',
            'price_per_hour',
        ];
    }

}