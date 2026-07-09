<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use LogsModelActivity, HasFactory;

    protected $fillable = [
        'listing_id',
        'path',
        'type',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function getUrlAttribute()
    {
        return url("storage/{$this->path}");
    }
    protected function activityLogName(): string
    {
        return 'asset';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'listing_id',
            'path',
            'type',
            'sort_order',
        ];
    }

}