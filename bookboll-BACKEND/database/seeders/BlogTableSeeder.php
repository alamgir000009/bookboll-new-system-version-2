<?php
namespace Database\Seeders;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BlogTableSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();

        $blogCategories = [
            [
                'name'        => 'Sports Tips',
                'slug'        => 'sports-tips',
                'description' => 'Latest sports tips and insights.',
            ],
            [
                'name'        => 'Venue Reviews',
                'slug'        => 'venue-reviews',
                'description' => 'Detailed reviews of sports venues and arenas.',
            ],
            [
                'name'        => 'Health & Fitness',
                'slug'        => 'health-fitness',
                'description' => 'Workout routines and health advice for athletes.',
            ],
            [
                'name'        => 'Qatar Sports',
                'slug'        => 'qatar-sports',
                'description' => 'Sports updates and events from Qatar.',
            ],
            [
                'name'        => 'Booking Guide',
                'slug'        => 'booking-guide',
                'description' => 'Guides for booking sports venues and events.',
            ],
        ];

        foreach ($blogCategories as $categoryData) {
            $category = PostCategory::firstOrCreate(
                ['slug' => $categoryData['slug']],
                $categoryData
            );

            $tagNames = ['Training', 'Wellness', 'Competition', 'Events', 'Nutrition', 'Strategy'];
            $tagIds   = collect($tagNames)->map(function ($tag) {
                return Tag::firstOrCreate(
                    ['slug' => Str::slug($tag)],
                    ['name' => $tag]
                )->id;
            })->toArray();

            for ($i = 1; $i <= 3; $i++) {
                $title = "{$category->name} Post {$i}";

                $post = $category->posts()->create([
                    'title'   => $title,
                    'slug'    => Str::slug($title . '-' . Str::random(4)),
                    'excerpt' => "This is a short excerpt for {$title}.",
                    'content' => "Full content of {$title}. Here you can write about {$category->name} insights and helpful tips.",
                    'status'         => Post::PUBLISHED,
                    'featured_image' => 'https://images.pexels.com/photos/274506/pexels-photo-274506.jpeg?auto=compress&cs=tinysrgb&w=120https://images.pexels.com/photos/399187/pexels-photo-399187.jpeg?auto=compress&cs=tinysrgb&w=800',
                    'user_id'        => $user->id,
                    'published_at'   => now(),
                    'seo_title'      => "{$title} | Sports Blog",
                    'seo_description' => "Read about {$category->name} and related sports insights.",
                    'read_time' => '5 min read',
                ]);

                $randomTagIds = array_rand(array_flip($tagIds), rand(2, 4));
                $post->tags()->sync((array) $randomTagIds);
            }
        }
    }
}
