<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'name',
        'slug',
        'color',
    ];

    public function posts()
    {
        return $this->belongsToMany(Post::class);
    }
    protected function activityLogName(): string
    {
        return 'tag';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'slug',
            'color',
        ];
    }

}