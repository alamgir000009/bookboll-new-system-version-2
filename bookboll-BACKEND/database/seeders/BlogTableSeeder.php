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

        $this->seedBusinessPosts($user, $tagIds, $image);
    }

    protected function seedBusinessPosts(User $user, array $tagIds, string $image): void
    {
        $category = PostCategory::firstOrCreate(
            ['slug' => 'business-growth'],
            [
                'name'        => 'Business Growth',
                'description' => 'Guides for venue owners, gyms and academies.',
            ]
        );

        $businessPosts = [
            [
                'title'   => 'Fill Quiet Hours at Your Padel Club',
                'slug'    => 'fill-quiet-hours-padel-club',
                'excerpt' => 'Practical pricing and promo ideas to pack off-peak courts.',
                'content' => '<p>Quiet midweek slots are where most venues leave money on the table. Use dynamic pricing, member-only windows, and short promos to fill them without discounting peak demand.</p><p>Start with a 14-day experiment: cut midweek rates 10–15%, push a WhatsApp blast to past bookers, and measure fill rate before you lock a permanent price.</p>',
                'featured'=> true,
            ],
            [
                'title'   => 'How to Launch Memberships on Bookboll',
                'slug'    => 'launch-memberships-on-bookboll',
                'excerpt' => 'Set up recurring plans, trials and check-in passes for your gym or academy.',
                'content' => '<p>Memberships work best when the plan, trial and visit pass are clear before the customer pays. Define monthly vs multi-month options, set a short trial, and require a payout method on your membership wallet before going live.</p>',
                'featured'=> false,
            ],
            [
                'title'   => 'Getting Your Booking Website Live',
                'slug'    => 'getting-booking-website-live',
                'excerpt' => 'From signup to your first public booking page in under an hour.',
                'content' => '<p>Pick your business username (it becomes your web address), add a venue and a listing, then publish. Your white-label site and marketplace listing share the same inventory — keep hours and prices accurate so customers book with confidence.</p>',
                'featured'=> false,
            ],
        ];

        foreach ($businessPosts as $data) {
            $post = Post::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'title'            => $data['title'],
                    'excerpt'          => $data['excerpt'],
                    'content'          => $data['content'],
                    'status'           => Post::PUBLISHED,
                    'type'             => Post::TYPE_BUSINESS,
                    'featured'         => $data['featured'],
                    'featured_image'   => $image,
                    'user_id'          => $user->id,
                    'post_category_id' => $category->id,
                    'published_at'     => now()->subDays(rand(1, 14)),
                    'seo_title'        => $data['title'].' | Bookboll for Business',
                    'seo_description'  => $data['excerpt'],
                    'read_time'        => '4 min read',
                ]
            );

            $pick = collect($tagIds)->shuffle()->take(rand(2, 3))->values()->all();
            $post->tags()->sync($pick);
        }
    }
}
