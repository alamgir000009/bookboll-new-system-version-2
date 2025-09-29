<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeBlock extends Model
{
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
}
