<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    use LogsModelActivity, TracksCreator;

    public const DRAFT     = 'draft';
    public const PUBLISHED = 'published';
    protected $fillable    = [
        'created_by',
        'slug',
        'tags',
        'title',
        'status',
        'excerpt',
        'content',
        'user_id',
        'featured',
        'seo_title',
        'read_time',
        'published_at',
        'featured_image',
        'seo_description',
        'post_category_id',
    ];

    public function scopePublished($query)
    {
        return $query->where('status', self::PUBLISHED);
    }

    public function getFeaturedImageAttribute($image)
    {
        if ($image) {
            if (preg_match('/^https?:\/\//', $image)) {
                return $image;
            }
            return url("storage/{$image}");
        }

        return null;
    }

    public function category()
    {
        return $this->belongsTo(PostCategory::class, 'post_category_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%")
                    ->orWhere('excerpt', 'LIKE', "%{$search}%")
                    ->orWhere('seo_title', 'LIKE', "%{$search}%")
                    ->orWhere('seo_description', 'LIKE', "%{$search}%")
                    ->orWhere('author_name', 'LIKE', "%{$search}%")
                    ->orWhereHas('category', function ($query) use ($search) {
                        $query->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('slug', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('tags', function ($query) use ($search) {
                        $query->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('slug', 'LIKE', "%{$search}%");
                    });
            });
        });
    }
    protected function activityLogName(): string
    {
        return 'post';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'slug',
            'tags',
            'title',
            'status',
            'excerpt',
            'content',
            'user_id',
            'featured',
            'seo_title',
            'read_time',
            'published_at',
            'featured_image',
            'seo_description',
            'post_category_id',
        ];
    }

}