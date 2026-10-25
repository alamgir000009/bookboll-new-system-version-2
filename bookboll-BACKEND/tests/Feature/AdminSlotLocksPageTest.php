<?php

/**
 * The admin panel's Slot Locks page.
 *
 * A hold takes a slot off sale for `default_bookings_slot_lock_time` minutes
 * while someone pays. If it sticks, a bookable slot is blocked with no booking
 * behind it and nothing but the five-minute sweep to clear it. This page is how
 * an admin sees and ends one.
 *
 * What these tests pin is mostly the distinction that matters operationally:
 * an ACTIVE hold blocks a booking and releasing it puts the slot back on sale;
 * an EXPIRED one already blocks nothing, because every availability check runs
 * through SlotLock::active().
 */

use App\Livewire\Admin\SlotLocks\Index as SlotLocksPage;
use App\Models\Listing;
use App\Models\SlotLock;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'customer'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

function locksAdmin(): User
{
    $admin = User::factory()->create(['status' => User::ACTIVE]);
    $admin->assignRole('super_admin');

    return $admin->fresh();
}

function courtFor(?User $provider = null, string $venueName = 'Al Sadd', string $courtName = 'Court 1'): Listing
{
    $provider ??= User::factory()->create();

    $venue = Venue::create([
        'name' => $venueName,
        'status' => Venue::ACTIVE,
        'owner_user_id' => $provider->id,
        'max_bookings_per_slot' => 1,
        'min_advance_booking' => 0,
    ]);

    return Listing::create([
        'name' => $courtName,
        'venue_id' => $venue->id,
        'status' => 'active',
        'base_price' => 100,
        'capacity' => 10,
        'slot_duration' => 60,
        'commission_rate' => 15,
        'payment_options' => [],
        'max_bookings_per_slot' => 1,
    ])->fresh('venue');
}

function holdSlot(Listing $listing, array $attributes = []): SlotLock
{
    return SlotLock::create(array_merge([
        'listing_id' => $listing->id,
        'user_id' => null,
        'date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'session_id' => 'slot_locked_from_'.md5(uniqid('', true)),
        'ip_address' => '203.0.113.10',
        'locked_until' => now()->addMinutes(10),
    ], $attributes));
}

/* ---------------------------------------------------------------------------
 | Access
 ---------------------------------------------------------------------------*/

it('is reachable only by a super admin', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $customer->assignRole('customer');

    $this->actingAs($customer)->get(route('slot-locks.index'))->assertRedirect();
    $this->get(route('slot-locks.index'))->assertRedirect(route('login'));
    $this->actingAs(locksAdmin())->get(route('slot-locks.index'))->assertOk();
});

/* ---------------------------------------------------------------------------
 | Listing what is held
 ---------------------------------------------------------------------------*/

it('shows the slot, court, venue, provider and who is holding it', function () {
    $provider = User::factory()->create(['name' => 'Rami Haddad']);
    $court = courtFor($provider, 'Al Sadd Sports', 'Padel Court 2');
    $customer = User::factory()->create(['name' => 'Dana Ruiz', 'email' => 'dana@example.test']);

    holdSlot($court, ['user_id' => $customer->id]);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->assertSee('Padel Court 2')
        ->assertSee('Al Sadd Sports')
        ->assertSee('Rami Haddad')
        ->assertSee('Dana Ruiz')
        ->assertSee('10:00 – 11:00');
});

it('marks a guest hold as a guest and shows its address instead of an email', function () {
    holdSlot(courtFor(), ['user_id' => null, 'ip_address' => '198.51.100.7']);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->assertSee('Guest')
        ->assertSee('198.51.100.7');
});

it('separates holds that block a booking from ones that have lapsed', function () {
    $court = courtFor();
    holdSlot($court, ['locked_until' => now()->addMinutes(5)]);
    holdSlot($court, ['start_time' => '12:00:00', 'end_time' => '13:00:00', 'locked_until' => now()->subMinute()]);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->assertViewHas('stats', fn (array $stats) => $stats['active'] === 1 && $stats['expired'] === 1)
        ->assertSee('Blocking')
        ->assertSee('Expired');
});

/* ---------------------------------------------------------------------------
 | Filtering
 ---------------------------------------------------------------------------*/

it('filters by venue, listing and provider', function () {
    $providerA = User::factory()->create(['name' => 'Provider A']);
    $providerB = User::factory()->create(['name' => 'Provider B']);

    $courtA = courtFor($providerA, 'Venue A', 'Court A');
    $courtB = courtFor($providerB, 'Venue B', 'Court B');

    holdSlot($courtA);
    holdSlot($courtB);

    $page = Livewire::actingAs(locksAdmin())->test(SlotLocksPage::class);

    $page->set('venue_id', (string) $courtA->venue_id)->assertViewHas('filteredTotal', 1);
    $page->set('venue_id', '')->set('listing_id', (string) $courtB->id)->assertViewHas('filteredTotal', 1);
    $page->set('listing_id', '')->set('provider_id', (string) $providerA->id)->assertViewHas('filteredTotal', 1);
});

it('clears a listing chosen under a venue when the venue changes', function () {
    $court = courtFor();

    holdSlot($court);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->set('listing_id', (string) $court->id)
        ->set('venue_id', (string) $court->venue_id)
        ->assertSet('listing_id', '');
});

it('filters by customer, by holder kind and by slot date', function () {
    $court = courtFor();
    $customer = User::factory()->create();

    holdSlot($court, ['user_id' => $customer->id, 'date' => '2026-11-02']);
    holdSlot($court, ['user_id' => null, 'start_time' => '14:00:00', 'date' => '2026-11-03']);

    $page = Livewire::actingAs(locksAdmin())->test(SlotLocksPage::class);

    $page->set('customer_id', (string) $customer->id)->assertViewHas('filteredTotal', 1);
    $page->set('customer_id', '')->set('holder', 'guest')->assertViewHas('filteredTotal', 1);
    $page->set('holder', 'customer')->assertViewHas('filteredTotal', 1);
    $page->set('holder', '')->set('date', '2026-11-03')->assertViewHas('filteredTotal', 1);
});

it('searches across customer, court, venue and address', function () {
    $court = courtFor(null, 'Lusail Arena', 'Tennis 4');
    $customer = User::factory()->create(['email' => 'findme@example.test']);

    holdSlot($court, ['user_id' => $customer->id, 'ip_address' => '192.0.2.44']);
    holdSlot(courtFor(null, 'Other Venue', 'Other Court'), ['start_time' => '16:00:00']);

    $page = Livewire::actingAs(locksAdmin())->test(SlotLocksPage::class);

    $page->set('q', 'findme@example.test')->assertViewHas('filteredTotal', 1);
    $page->set('q', 'Lusail')->assertViewHas('filteredTotal', 1);
    $page->set('q', 'Tennis 4')->assertViewHas('filteredTotal', 1);
    $page->set('q', '192.0.2.44')->assertViewHas('filteredTotal', 1);
});

it('offers only the venues and courts that actually have holds', function () {
    $held = courtFor(null, 'Held Venue', 'Held Court');
    courtFor(null, 'Idle Venue', 'Idle Court');

    holdSlot($held);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->assertViewHas('venues', fn (array $v) => in_array('Held Venue', $v, true) && ! in_array('Idle Venue', $v, true))
        ->assertViewHas('listings', fn (array $l) => in_array('Held Court', $l, true) && ! in_array('Idle Court', $l, true));
});

/* ---------------------------------------------------------------------------
 | Releasing
 ---------------------------------------------------------------------------*/

it('releases a single hold', function () {
    $lock = holdSlot(courtFor());

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->call('release', $lock->id)
        ->assertSet('errorMessage', null);

    expect(SlotLock::whereKey($lock->id)->exists())->toBeFalse();
});

it('says a freed slot is back on sale, and says nothing changed for a lapsed one', function () {
    $court = courtFor();
    $active = holdSlot($court, ['locked_until' => now()->addMinutes(5)]);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->call('release', $active->id)
        ->assertSee('back on sale');

    $lapsed = holdSlot($court, ['start_time' => '12:00:00', 'locked_until' => now()->subMinute()]);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->call('release', $lapsed->id)
        ->assertSee('already lapsed');
});

it('releases only the ticked holds', function () {
    $court = courtFor();
    $doomed = holdSlot($court);
    $spared = holdSlot($court, ['start_time' => '15:00:00']);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->set('selected', [(string) $doomed->id])
        ->call('releaseSelected');

    expect(SlotLock::whereKey($doomed->id)->exists())->toBeFalse()
        ->and(SlotLock::whereKey($spared->id)->exists())->toBeTrue();
});

it('releases every hold on one listing and leaves other courts alone', function () {
    $stuck = courtFor(null, 'Venue', 'Stuck Court');
    $other = courtFor(null, 'Venue 2', 'Fine Court');

    holdSlot($stuck);
    holdSlot($stuck, ['start_time' => '12:00:00']);
    $untouched = holdSlot($other);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->call('releaseAllForListing', $stuck->id);

    expect(SlotLock::where('listing_id', $stuck->id)->count())->toBe(0)
        ->and(SlotLock::whereKey($untouched->id)->exists())->toBeTrue();
});

it('clears expired holds without touching ones still blocking', function () {
    $court = courtFor();
    $active = holdSlot($court, ['locked_until' => now()->addMinutes(5)]);
    $lapsed = holdSlot($court, ['start_time' => '12:00:00', 'locked_until' => now()->subMinute()]);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->call('releaseExpired');

    expect(SlotLock::whereKey($lapsed->id)->exists())->toBeFalse()
        ->and(SlotLock::whereKey($active->id)->exists())->toBeTrue();
});

it('releases everything matching the filters and nothing outside them', function () {
    $courtA = courtFor(null, 'Venue A', 'Court A');
    $courtB = courtFor(null, 'Venue B', 'Court B');

    $matching = holdSlot($courtA);
    $outside = holdSlot($courtB);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->set('venue_id', (string) $courtA->venue_id)
        ->call('releaseMatchingFilters');

    expect(SlotLock::whereKey($matching->id)->exists())->toBeFalse()
        ->and(SlotLock::whereKey($outside->id)->exists())->toBeTrue();
});

it('refuses to release by filter when no filter is set', function () {
    $lock = holdSlot(courtFor());

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->call('releaseMatchingFilters')
        ->assertSet('statusMessage', null);

    expect(SlotLock::whereKey($lock->id)->exists())->toBeTrue();
});

it('releases every hold in the system', function () {
    holdSlot(courtFor(null, 'A', 'A1'));
    holdSlot(courtFor(null, 'B', 'B1'));

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->call('releaseAll');

    expect(SlotLock::count())->toBe(0);
});

it('reports an already-released hold rather than failing', function () {
    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->call('release', 999999)
        ->assertSet('statusMessage', null)
        ->assertSee('already been released');
});

it('records every release in the activity log', function () {
    $admin = locksAdmin();
    $lock = holdSlot(courtFor(), ['locked_until' => now()->addMinutes(5)]);

    Livewire::actingAs($admin)
        ->test(SlotLocksPage::class)
        ->call('release', $lock->id);

    $entry = Activity::query()
        ->where('log_name', 'slot_lock')
        ->where('description', 'like', 'Released%')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->properties['released_count'])->toBe(1)
        ->and($entry->properties['active_freed'])->toBe(1);
});

/* ---------------------------------------------------------------------------
 | Detail panel
 ---------------------------------------------------------------------------*/

it('opens and closes the detail panel', function () {
    $lock = holdSlot(courtFor(), ['session_id' => 'slot_locked_from_abc123']);

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->call('inspect', $lock->id)
        ->assertSet('inspecting', $lock->id)
        ->assertSee('Hold details')
        ->assertSee('slot_locked_from_abc123')
        ->call('closeInspector')
        ->assertSet('inspecting', null);
});

it('closes the panel when the hold it was showing is released', function () {
    $lock = holdSlot(courtFor());

    Livewire::actingAs(locksAdmin())
        ->test(SlotLocksPage::class)
        ->call('inspect', $lock->id)
        ->call('release', $lock->id)
        ->assertSet('inspecting', null);
});
