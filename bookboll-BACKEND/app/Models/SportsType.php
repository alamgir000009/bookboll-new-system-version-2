<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportsType extends Model
{
    protected $fillable = [
        'slug',
        'icon',
        'image',
        'color',
        'title',
        'status',
        'sub_title',
        'sort_order',
    ];

    public function sportsCategories()
    {
        return $this->hasMany(SportsCategory::class);
    }

    public function listings()
    {
        return $this->hasManyThrough(Listing::class, SportsCategory::class, 'sports_type_id', 'sports_category_id', 'id', 'id');
    }

    public function getImageAttribute($image)
    {
        return $image ? url("storage/{$image}") : null;
    }

}
