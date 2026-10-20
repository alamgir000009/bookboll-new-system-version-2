<?php

/**
 * Copying a listing into a venue.
 *
 * The copy goes through store(), so it gets the same validation and the same
 * access check on the TARGET venue as a listing typed by hand. These pin the
 * parts that are easy to get wrong: that a provider cannot copy into — or out
 * of — another business's venue, and that a copy is not born approved.
 */

use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'provider_admin'] as $r) {
        Role::findOrCreate($r, 'web');
    }

    $this->admin = dupUser('super_admin');
    $this->owner = dupUser('provider_admin');
    $this->venueA = dupVenue($this->owner, 'Al Gharafa');
    $this->venueB = dupVenue($this->owner, 'Abu Hamor');
    $this->listing = dupListing($this->venueA, '9v9 Full Court');
});

function dupUser(string $role): User
{
    $u = User::factory()->create(['status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $u->assignRole($role);

    return $u->fresh();
}

function dupVenue(User $owner, string $name): Venue
{
    return Venue::create([
        'name' => $name, 'status' => Venue::ACTIVE, 'owner_user_id' => $owner->id,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

function dupListing(Venue $venue, string $name): Listing
{
    return Listing::create([
        'name' => $name, 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 18, 'slot_duration' => 60,
        'max_bookings_per_slot' => 1, 'payment_options' => ['site_deposit_enabled' => true],
        'approval_status' => Listing::APPROVAL_APPROVED,
    ]);
}

function duplicateInto(User $actor, Listing $listing, $venueId, array $extra = [])
{
    Sanctum::actingAs($actor);

    return test()->postJson("/api/admin/listings/dashboard/{$listing->id}/duplicate",
        array_merge(['venue_id' => $venueId], $extra));
}

it('copies a listing into another venue of the same business', function () {
    duplicateInto($this->owner, $this->listing, $this->venueB->id)->assertOk();

    $copy = Listing::where('venue_id', $this->venueB->id)->first();

    expect($copy)->not->toBeNull();
    expect($copy->name)->toBe('9v9 Full Court (copy)');
    expect((float) $copy->base_price)->toBe(250.0);
    expect((int) $copy->capacity)->toBe(18);
    // The original is untouched.
    expect(Listing::where('venue_id', $this->venueA->id)->count())->toBe(1);
});

it('copies into the same venue when that is what was asked', function () {
    duplicateInto($this->owner, $this->listing, $this->venueA->id)->assertOk();

    expect(Listing::where('venue_id', $this->venueA->id)->pluck('name')->sort()->values()->all())
        ->toBe(['9v9 Full Court', '9v9 Full Court (copy)']);
});

it('does not number the first copy, and does number the next', function () {
    duplicateInto($this->owner, $this->listing, $this->venueB->id)->assertOk();
    duplicateInto($this->owner, $this->listing, $this->venueB->id)->assertOk();

    // Compared as a set: ')' sorts after ' ', so "(copy 2)" precedes "(copy)".
    expect(Listing::where('venue_id', $this->venueB->id)->pluck('name')->all())
        ->toEqualCanonicalizing(['9v9 Full Court (copy)', '9v9 Full Court (copy 2)']);
});

it('takes a name when one is given', function () {
    duplicateInto($this->owner, $this->listing, $this->venueB->id, ['name' => '9v9 Full Court - Abu Hamor'])
        ->assertOk();

    expect(Listing::where('venue_id', $this->venueB->id)->value('name'))->toBe('9v9 Full Court - Abu Hamor');
});

it('never carries the original approval over to the copy', function () {
    // A copy is a new listing nobody has reviewed. Inheriting "approved" would
    // put unreviewed content straight onto the marketplace.
    expect($this->listing->approval_status)->toBe(Listing::APPROVAL_APPROVED);

    duplicateInto($this->owner, $this->listing, $this->venueB->id)->assertOk();

    expect(Listing::where('venue_id', $this->venueB->id)->value('approval_status'))
        ->toBe(Listing::APPROVAL_PENDING);
});

it('refuses to copy into another business venue', function () {
    $stranger = dupUser('provider_admin');
    $theirVenue = dupVenue($stranger, 'Someone Else');

    duplicateInto($this->owner, $this->listing, $theirVenue->id)->assertStatus(403);

    expect(Listing::where('venue_id', $theirVenue->id)->count())->toBe(0);
});

it('refuses to copy a listing the caller cannot see', function () {
    $stranger = dupUser('provider_admin');
    $theirListing = dupListing(dupVenue($stranger, 'Their Venue'), 'Their Court');

    // Being able to write to the destination does not entitle anyone to copy
    // from somewhere they cannot see.
    duplicateInto($this->owner, $theirListing, $this->venueB->id)->assertStatus(403);

    expect(Listing::where('venue_id', $this->venueB->id)->count())->toBe(0);
});

it('lets a platform admin copy across businesses', function () {
    $stranger = dupUser('provider_admin');
    $theirVenue = dupVenue($stranger, 'Another Business');

    duplicateInto($this->admin, $this->listing, $theirVenue->id)->assertOk();

    expect(Listing::where('venue_id', $theirVenue->id)->count())->toBe(1);
});

it('asks which venue rather than guessing one', function () {
    duplicateInto($this->owner, $this->listing, null)
        ->assertStatus(422)->assertJsonValidationErrors(['venue_id']);

    // A venue that does not exist is refused by store()'s own validation.
    duplicateInto($this->owner, $this->listing, 999999)->assertStatus(422);
});

it('copies the gallery, which lives in assets and not the photos column', function () {
    // Almost every listing's pictures are asset rows on this platform; the
    // photos column is mostly empty. A copy that only carried the column came
    // out with no images at all.
    foreach ([['uploads/listings/a.jpg', 0], ['uploads/listings/b.jpg', 1]] as [$path, $order]) {
        \App\Models\Asset::create([
            'path' => $path, 'type' => 'image', 'sort_order' => $order,
            'assetable_type' => Listing::class, 'assetable_id' => $this->listing->id,
        ]);
    }

    duplicateInto($this->owner, $this->listing, $this->venueB->id)->assertOk();

    $copy = Listing::where('venue_id', $this->venueB->id)->first();

    // Same files, same order — pointed at, not re-uploaded.
    expect($copy->assets()->pluck('path')->all())
        ->toBe(['uploads/listings/a.jpg', 'uploads/listings/b.jpg']);
    // And the original keeps its own.
    expect($this->listing->assets()->count())->toBe(2);
});

it('copies the dynamic pricing groups as new rows', function () {
    \App\Models\PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Peak', 'price_per_hour' => 400,
        'days' => [1, 2], 'start_time' => '18:00', 'end_time' => '22:00', 'sort_order' => 0,
    ]);

    duplicateInto($this->owner, $this->listing, $this->venueB->id)->assertOk();

    $copy = Listing::where('venue_id', $this->venueB->id)->first();
    $group = $copy->pricingGroups()->first();

    expect($group->name)->toBe('Peak');
    expect((float) $group->price_per_hour)->toBe(400.0);
    // A new row, not the original's — carrying the id would have edited it.
    expect($group->listing_id)->toBe($copy->id);
    expect($this->listing->pricingGroups()->count())->toBe(1);
});

it('copies the weekly schedule and the payment methods', function () {
    duplicateInto($this->owner, $this->listing, $this->venueB->id)->assertOk();

    $copy = Listing::where('venue_id', $this->venueB->id)->first();

    expect($copy->schedules()->count())->toBeGreaterThan(0);
    expect($copy->payment_options['site_deposit_enabled'])->toBeTrue();
});

it('is visible on the marketplace once it is approved', function () {
    // The copy arrives pending. Approving it is all that should stand between
    // it and the catalogue.
    duplicateInto($this->owner, $this->listing, $this->venueB->id)->assertOk();

    $copy = Listing::where('venue_id', $this->venueB->id)->first();
    expect($copy->show_on_marketplace)->not->toBeFalse();

    $before = collect(test()->withHeader('from', 'frontend-web')
        ->getJson('/api/marketplace/listings')->json('data.listings') ?? [])->pluck('name');
    expect($before)->not->toContain($copy->name);

    $copy->update(['approval_status' => Listing::APPROVAL_APPROVED]);

    $after = collect(test()->withHeader('from', 'frontend-web')
        ->getJson('/api/marketplace/listings')->json('data.listings') ?? [])->pluck('name');
    expect($after)->toContain($copy->name);
});

it('refuses to copy an off-marketplace listing on a free plan, rather than quietly putting it on the marketplace', function () {
    // Hiding a listing from Bookboll is a Premium feature. A copy is a NEW
    // off-marketplace listing, so it is a new use of that feature — refused
    // with 402. The alternative, silently flipping the copy on to the
    // marketplace, would publish something the provider deliberately hid.
    $this->listing->update(['show_on_marketplace' => false]);

    duplicateInto($this->owner, $this->listing, $this->venueB->id)->assertStatus(402);

    expect(Listing::where('venue_id', $this->venueB->id)->count())->toBe(0);
    expect($this->listing->fresh()->show_on_marketplace)->toBeFalse();
});

it('approves a copy made by someone who can approve listings', function () {
    // An admin was sent an approval notification for their own copy, then
    // approved it in the same dashboard. A review that reviews nothing.
    duplicateInto($this->admin, $this->listing, $this->venueB->id)->assertOk();

    $copy = Listing::where('venue_id', $this->venueB->id)->first();
    expect($copy->approval_status)->toBe(Listing::APPROVAL_APPROVED);

    // Live on the marketplace immediately, with no approval step.
    expect(collect(test()->withHeader('from', 'frontend-web')
        ->getJson('/api/marketplace/listings')->json('data.listings') ?? [])->pluck('name'))
        ->toContain($copy->name);
});

it('still sends a provider copy to the queue', function () {
    duplicateInto($this->owner, $this->listing, $this->venueB->id)->assertOk();

    expect(Listing::where('venue_id', $this->venueB->id)->value('approval_status'))
        ->toBe(Listing::APPROVAL_PENDING);
});

it('does not launder an unreviewed listing through a copy', function () {
    // Copying something pending or rejected leaves the copy pending, even for
    // an admin: approving here would publish content nobody reviewed.
    $this->listing->update(['approval_status' => Listing::APPROVAL_PENDING]);

    duplicateInto($this->admin, $this->listing, $this->venueB->id)->assertOk();

    expect(Listing::where('venue_id', $this->venueB->id)->value('approval_status'))
        ->toBe(Listing::APPROVAL_PENDING);
});

it('does not notify an approver about their own listing', function () {
    duplicateInto($this->admin, $this->listing, $this->venueB->id)->assertOk();

    expect($this->admin->fresh()->notifications()->count())->toBe(0);
});
