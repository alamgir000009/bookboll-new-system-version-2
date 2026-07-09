<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class TimeBlock extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'end_time',
        'start_time',
        'special_day_id',
        'price_per_hour',
        'price_currency',
    ];

    public function specialDay()
    {
        return $this->belongsTo(SpecialDay::class);
    }
    protected function activityLogName(): string
    {
        return 'time_block';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'end_time',
            'start_time',
            'special_day_id',
            'price_per_hour',
            'price_currency',
        ];
    }

}