<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportsType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'status',
    ];

    public function sportsCategories()
    {
        return $this->hasMany(SportsCategory::class);
    }
}
