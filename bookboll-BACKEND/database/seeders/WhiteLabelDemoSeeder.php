<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\Post;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * A working white-label provider so the branded storefront can actually be opened.
 *
 * The database ships with every venue, programme and post owned by the platform
 * (owner_user_id is null everywhere), so no provider owns anything and no brand
 * resolves. This gives one provider a business row plus real content to show.
 *
 * Run it explicitly — it is NOT part of DatabaseSeeder:
 *
 *     php artisan db:seed --class=WhiteLabelDemoSeeder
 *
 * Then open the storefront at:  https://bookboll.com/?b=padelrepublic
 * (In production a brand resolves by subdomain; `?b=` works on localhost only.)
 */
class WhiteLabelDemoSeeder extends Seeder
{
    public const SLUG = 'padelrepublic';

    public function run(): void
    {
        $owner = User::whereHas('roles', fn ($q) => $q->where('name', 'provider_admin'))->first()
            ?: User::orderBy('id')->first();

        if (! $owner) {
            $this->command?->warn('No users found — seed users first.');

            return;
        }

        $venue = Venue::has('listings')->orderBy('id')->first() ?: Venue::orderBy('id')->first();
        if (! $venue) {
            $this->command?->warn('No venues found — seed venues first.');

            return;
        }

        $business = Business::updateOrCreate(
            ['username' => self::SLUG],
            [
                'owner_id'               => $owner->id,
                'name'                   => 'Padel Republic',
                'status'                 => Business::STATUS_ACTIVE,
                'brand_enabled'          => true,
                'brand_subdomain'        => self::SLUG,
                'brand_name'             => 'Padel Republic',
                'brand_tagline'          => 'Play more padel, book in seconds',
                'brand_primary'          => '#7c3aed',
                'brand_accent'           => '#a78bfa',
                'brand_meta_title'       => 'Padel Republic — book a court',
                'brand_meta_description' => 'Book padel courts and join our academy programmes.',
                'sort_space'             => 'recommended',
                'sort_venue'             => 'recommended',
                'brand_languages'        => [],
                'site_pages'             => [
                    'home'    => ['hero_image' => ''],
                    'about'   => [
                        'text'       => "Padel Republic is a members-first padel club.\n\nFour indoor courts, coaching for every level, and a league that runs all year.",
                        'hero_title' => 'About Padel Republic',
                    ],
                    'contact' => [
                        'phone'   => '+974 4000 0000',
                        'email'   => 'hello@padelrepublic.example',
                        'address' => $venue->address ?: 'Doha, Qatar',
                        'hours'   => 'Daily · 7:00 AM – 11:00 PM',
                    ],
                ],
                'brand_whitelabel'       => false,
                'plan'                   => 'free',
            ]
        );

        // Give the provider content to show — otherwise every storefront module
        // derives to false and the site renders empty.
        $venue->update(['owner_user_id' => $owner->id]);
        Listing::where('venue_id', $venue->id)->update(['show_on_provider_site' => true]);

        $program = ClassProgram::where('status', 'active')->orderBy('id')->first();
        if ($program) {
            $program->update([
                'mapped_owner_user_id'  => $owner->id,
                'show_on_provider_site' => true,
                'is_draft'              => false,
            ]);
        }

        $listings = Listing::where('venue_id', $venue->id)->count();
        $this->command?->info("White-label provider ready: {$business->brand_name} (@{$business->username})");
        $this->command?->info("  owner: {$owner->name} (#{$owner->id})");
        $this->command?->info("  venue: {$venue->name} · {$listings} listing(s)");
        $this->command?->info('  program: ' . ($program?->name ?: 'none'));
        $this->command?->info('  open: https://bookboll.com/?b=' . self::SLUG);
    }
}
