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

        if (! $user) {
            $this->command?->warn('BlogTableSeeder skipped: no users found.');

            return;
        }

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

        $tagNames = ['Training', 'Wellness', 'Competition', 'Events', 'Nutrition', 'Strategy'];
        $tagIds   = collect($tagNames)->map(function ($tag) {
            return Tag::firstOrCreate(
                ['slug' => Str::slug($tag)],
                ['name' => $tag]
            )->id;
        })->all();

        $image = 'https://images.pexels.com/photos/274506/pexels-photo-274506.jpeg?auto=compress&cs=tinysrgb&w=800';

        foreach ($blogCategories as $categoryData) {
            $category = PostCategory::firstOrCreate(
                ['slug' => $categoryData['slug']],
                $categoryData
            );

            for ($i = 1; $i <= 3; $i++) {
                $title = "{$category->name} Post {$i}";
                $slug  = Str::slug($title);

                $post = Post::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'title'           => $title,
                        'excerpt'         => "This is a short excerpt for {$title}.",
                        'content'         => "Full content of {$title}. Here you can write about {$category->name} insights and helpful tips.",
                        'status'          => Post::PUBLISHED,
                        'type'            => Post::TYPE_MAIN,
                        'featured'        => $category->slug === 'sports-tips' && $i === 1,
                        'featured_image'  => $image,
                        'user_id'         => $user->id,
                        'post_category_id'=> $category->id,
                        'published_at'    => now()->subDays(rand(1, 30)),
                        'seo_title'       => "{$title} | Sports Blog",
                        'seo_description' => "Read about {$category->name} and related sports insights.",
                        'read_time'       => '5 min read',
                    ]
                );

                $pick = collect($tagIds)->shuffle()->take(rand(2, 4))->values()->all();
                $post->tags()->sync($pick);
            }
        }

        // Business-blog starter posts live in their own seeder so they can be run
        // without the placeholder MAIN posts above.
        $this->call(BusinessBlogSeeder::class);
    }
}
