<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

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
}

