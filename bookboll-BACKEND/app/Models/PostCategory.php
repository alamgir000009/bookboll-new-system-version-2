<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class PostCategory extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'name',
        'slug',
        'description',
    ];

    public function posts()
    {
        return $this->hasMany(Post::class, 'post_category_id');
    }
    protected function activityLogName(): string
    {
        return 'post_category';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'slug',
            'description',
        ];
    }

}