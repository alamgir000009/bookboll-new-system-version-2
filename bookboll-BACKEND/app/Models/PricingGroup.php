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
        'color',
        'days',
        'end_time',
        'start_time',
        'listing_id',
        'price_per_hour',
        'currency',
        'sort_order',
    ];

    protected $casts = [
        'price_per_hour' => 'decimal:2',
        'days'           => 'array',
        'sort_order'     => 'integer',
    ];

    public function getStartTimeAttribute($start_time)
    {
        return $start_time ? date('H:i', strtotime($start_time)) : null;
    }

    public function getEndTimeAttribute($end_time)
    {
        return $end_time ? date('H:i', strtotime($end_time)) : null;
    }

    /*
     * The column is a TIME, so it reads back as "19:00:00" while the accessor
     * above hands out "19:00" — and that is the shape every caller fills back
     * in. Without normalising on the way IN, "19:00" never equals "19:00:00",
     * so both columns looked dirty on every single save: a pointless UPDATE per
     * group, `updated_at` bumped, and an activity-log entry for a change nobody
     * made. Normalise here so an unchanged save writes nothing at all.
     */
    public function setStartTimeAttribute($value): void
    {
        $this->attributes['start_time'] = $value ? date('H:i:s', strtotime($value)) : null;
    }

    public function setEndTimeAttribute($value): void
    {
        $this->attributes['end_time'] = $value ? date('H:i:s', strtotime($value)) : null;
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