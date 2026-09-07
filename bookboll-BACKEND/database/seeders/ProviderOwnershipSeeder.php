<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Give every existing venue an OWNER, so the provider side of the dashboard has
 * something to show.
 *
 * The imported catalogue has venues.owner_user_id = NULL everywhere, which means
 * "Bookboll runs this venue". Under AccessScope a provider then owns nothing and
 * their dashboard is empty — correct, but impossible to test against.
 *
 * By default this creates a dedicated provider account (so you can sign in AS a
 * provider and see the scoping work). Point it at an existing user instead with:
 *
 *     PROVIDER_USER_ID=2 php artisan db:seed --class=ProviderOwnershipSeeder
 *
 * Run it plainly for the default account:
 *
 *     php artisan db:seed --class=ProviderOwnershipSeeder
 *
 * Idempotent — re-running just re-asserts ownership. NOT part of DatabaseSeeder.
 */
class ProviderOwnershipSeeder extends Seeder
{
    public const EMAIL    = 'provider@bookboll.com';
    public const PASSWORD = '12345678';
    public const USERNAME = 'bookbollsports';

    public function run(): void
    {
        $owner = $this->resolveOwner();
        if (! $owner) {
            return;
        }

        if (! $owner->hasRole('provider_admin')) {
            $owner->assignRole('provider_admin');
            $this->command?->info("Granted provider_admin to #{$owner->id}.");
        }

        $business = $this->ensureBusiness($owner);

        // ── Hand the whole catalogue to this provider ──
        $venueIds = Venue::pluck('id')->all();
        $venuesUpdated = Venue::whereIn('id', $venueIds)
            ->update(['owner_user_id' => $owner->id]);

        // Listings follow their venue, so nothing to set there — but make sure
        // they are visible on the provider's own site.
        $listingsShown = Listing::whereIn('venue_id', $venueIds)
            ->update(['show_on_provider_site' => true]);

        // Programmes carry their own owner column.
        $programsUpdated = 0;
        if (ClassProgram::query()->exists()) {
            $programsUpdated = ClassProgram::query()->update([
                'mapped_owner_user_id'  => $owner->id,
                'owner_user_id'         => (string) $owner->uuid,
                'show_on_provider_site' => true,
            ]);
        }

        // Keep the legacy pivot in step so anything still reading it agrees.
        $now = now();
        DB::table('user_venue')->where('user_id', $owner->id)->delete();
        DB::table('user_venue')->insert(array_map(fn ($vid) => [
            'user_id'    => $owner->id,
            'venue_id'   => $vid,
            'created_at' => $now,
            'updated_at' => $now,
        ], $venueIds));

        $listingCount = Listing::whereIn('venue_id', $venueIds)->count();

        $this->command?->newLine();
        $this->command?->info('Provider ownership assigned');
        $this->command?->info("  owner    : {$owner->name} <{$owner->email}> (#{$owner->id})");
        $this->command?->info("  business : {$business->name} (@{$business->username})");
        $this->command?->info("  venues   : {$venuesUpdated}");
        $this->command?->info("  listings : {$listingCount} (visible on their site: {$listingsShown})");
        $this->command?->info("  programs : {$programsUpdated}");
        $this->command?->info("  pivot    : " . count($venueIds) . ' user_venue rows');
        $this->command?->newLine();

        if ($this->createdNewAccount) {
            $this->command?->warn('Sign in at the admin app with:');
            $this->command?->warn('  email    : ' . self::EMAIL);
            $this->command?->warn('  password : ' . self::PASSWORD);
        }
        $this->command?->info('White-label storefront: http://localhost:5173/?b=' . $business->username);
    }

    protected bool $createdNewAccount = false;

    /** The user to make owner: PROVIDER_USER_ID if given, else the default account. */
    protected function resolveOwner(): ?User
    {
        $explicit = env('PROVIDER_USER_ID');

        if ($explicit) {
            $user = User::find((int) $explicit);
            if (! $user) {
                $this->command?->error("No user #{$explicit} — nothing done.");

                return null;
            }
            // A platform admin owning venues would be scoped to them and lose the
            // global view, so make that trade-off explicit rather than silent.
            if ($user->hasAnyRole(['super_admin', 'admin'])) {
                $this->command?->warn(
                    "#{$user->id} is a platform admin. Adding provider_admin keeps their global "
                    . 'access (super_admin/admin always win in AccessScope), so they will still '
                    . 'see every venue — not a true provider-scoped view.'
                );
            }

            return $user;
        }

        $user = User::where('email', self::EMAIL)->first();
        if ($user) {
            return $user;
        }

        $this->createdNewAccount = true;

        return User::create([
            'name'     => 'Bookboll Sports',
            'email'    => self::EMAIL,
            'password' => self::PASSWORD,
            'status'   => User::ACTIVE,
            'phone'    => '00000000',
        ]);
    }

    protected function ensureBusiness(User $owner): Business
    {
        $business = Business::where('owner_id', $owner->id)->first();
        if ($business) {
            return $business;
        }

        // Keep the username unique if something already claimed it.
        $username = self::USERNAME;
        if (Business::where('username', $username)->orWhere('brand_subdomain', $username)->exists()) {
            $username = $username . '-' . $owner->id;
        }

        return Business::create([
            'owner_id'        => $owner->id,
            'name'            => 'Bookboll Sports',
            'username'        => $username,
            'brand_subdomain' => $username,
            'status'          => Business::STATUS_ACTIVE,
            'brand_enabled'   => true,
            'brand_name'      => 'Bookboll Sports',
            'brand_tagline'   => 'Book courts across Qatar',
            'business_types'  => ['sports_facility'],
        ]);
    }
}
