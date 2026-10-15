<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Starter content for the "Bookboll for Business" blog
 * (business.bookboll.com/blog — posts with type=business, owner_user_id NULL).
 *
 * Split out of BlogTableSeeder so the business blog can be populated on its own:
 * BlogTableSeeder::run() also creates 15 placeholder MAIN posts ("Sports Tips
 * Post 1", …) for the customer marketplace blog, which must never be run against
 * an environment that already carries real marketplace content.
 *
 *   php artisan db:seed --class=BusinessBlogSeeder
 *
 * Idempotent: posts are matched on slug, so re-running refreshes them in place
 * instead of duplicating. These are starter posts — replace them with real
 * articles from the dashboard (Blog → For Business).
 */
class BusinessBlogSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();

        if (! $user) {
            $this->command?->warn('BusinessBlogSeeder skipped: no users found.');

            return;
        }

        $category = PostCategory::firstOrCreate(
            ['slug' => 'business-growth'],
            [
                'name'        => 'Business Growth',
                'description' => 'Guides for venue owners, gyms and academies.',
            ]
        );

        $tagIds = collect(['Strategy', 'Events', 'Wellness'])
            ->map(fn ($tag) => Tag::firstOrCreate(['slug' => Str::slug($tag)], ['name' => $tag])->id)
            ->all();

        $image = 'https://images.pexels.com/photos/274506/pexels-photo-274506.jpeg?auto=compress&cs=tinysrgb&w=800';

        $businessPosts = [
            [
                'title'   => 'Fill Quiet Hours at Your Padel Club',
                'slug'    => 'fill-quiet-hours-padel-club',
                'excerpt' => 'Practical pricing and promo ideas to pack off-peak courts.',
                'content' => '<p>Quiet midweek slots are where most venues leave money on the table. Use dynamic pricing, member-only windows, and short promos to fill them without discounting peak demand.</p><p>Start with a 14-day experiment: cut midweek rates 10–15%, push a WhatsApp blast to past bookers, and measure fill rate before you lock a permanent price.</p>',
                'featured' => true,
            ],
            [
                'title'   => 'How to Launch Memberships on Bookboll',
                'slug'    => 'launch-memberships-on-bookboll',
                'excerpt' => 'Set up recurring plans, trials and check-in passes for your gym or academy.',
                'content' => '<p>Memberships work best when the plan, trial and visit pass are clear before the customer pays. Define monthly vs multi-month options, set a short trial, and require a payout method on your membership wallet before going live.</p>',
                'featured' => false,
            ],
            [
                'title'   => 'Getting Your Booking Website Live',
                'slug'    => 'getting-booking-website-live',
                'excerpt' => 'From signup to your first public booking page in under an hour.',
                'content' => '<p>Pick your business username (it becomes your web address), add a venue and a listing, then publish. Your white-label site and marketplace listing share the same inventory — keep hours and prices accurate so customers book with confidence.</p>',
                'featured' => false,
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
                    // The business blog is a platform publication, never a provider's
                    // own site blog — forOwner(null) is what the public route reads.
                    'owner_user_id'    => null,
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

            $post->tags()->sync(collect($tagIds)->shuffle()->take(rand(2, 3))->values()->all());
        }

        $this->command?->info('Seeded '.count($businessPosts).' Bookboll for Business posts.');
    }
}
