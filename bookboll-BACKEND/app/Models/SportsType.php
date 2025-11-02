<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportsType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'status',
    ];

    public function sportsCategories()
    {
        return $this->hasMany(SportsCategory::class);
    }
}
