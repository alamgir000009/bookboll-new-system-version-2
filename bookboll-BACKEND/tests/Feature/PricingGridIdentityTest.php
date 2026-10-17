<?php

/**
 * Saving the pricing grid edits rows; it does not replace them.
 *
 * syncPricing() used to delete every pricing group and cell for the listing and
 * re-insert the payload, so a group's id survived only until the next Save. That
 * burned a block of auto-increment ids per save, reset `created_by`/`created_at`
 * to whoever saved last, wiped the group's stored currency, and moved the
 * `source_id` the slot API reports for a group nobody had touched — which also
 * made "the admin changed the price" and "the admin opened and closed the form"
 * look identical afterwards.
 */

use App\Models\Listing;
use App\Models\PricingGroup;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    $this->staff = User::factory()->create();
    $this->staff->assignRole('admin');

    $this->venue = Venue::create([
        'name' => 'Grid Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    $this->listing = Listing::create([
        'name' => 'Court A', 'venue_id' => $this->venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 10, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    foreach (['sunday', 'monday'] as $day) {
        foreach ([['venue_id' => $this->venue->id], ['listing_id' => $this->listing->id]] as $owner) {
            Schedule::create($owner + [
                'day' => $day, 'is_open' => true, 'start_time' => '18:00', 'end_time' => '00:00',
            ]);
        }
    }

    $this->put = fn (array $groups, array $cells = []) => $this
        ->actingAs($this->staff)
        ->withHeaders(['from' => 'admin-web'])
        ->putJson('/api/admin/listings/dashboard/' . $this->listing->id, [
            'name'           => 'Court A',
            'location_id'    => $this->venue->id,
            'description'    => 'x',
            'base_price'     => 250,
            'slot_duration'  => 60,
            'pricing_groups' => $groups,
            'pricing_cells'  => $cells,
        ]);

    // What the editor posts for a brand-new group: a `tmp-g-…` id it made up.
    $this->fresh = [
        ['id' => 'tmp-g-1', 'name' => 'Peak',  'price' => 400, 'sort_order' => 0,
         'start_time' => '22:00', 'end_time' => '00:00', 'days' => '0,1'],
        ['id' => 'tmp-g-2', 'name' => 'Quiet', 'price' => 300, 'sort_order' => 1,
         'start_time' => '18:00', 'end_time' => '20:00', 'days' => '0,1'],
    ];

    // …and what it posts back once those rows exist: their real ids, as strings.
    $this->reposted = function (array $overrides = []) {
        $stored = PricingGroup::where('listing_id', $this->listing->id)->orderBy('sort_order')->get();

        return $stored->map(fn (PricingGroup $g, $i) => array_replace([
            'id'         => (string) $g->id,
            'name'       => $g->name,
            'price'      => (float) $g->price_per_hour,
            'sort_order' => (int) $g->sort_order,
            'start_time' => $g->start_time,
            'end_time'   => $g->end_time,
            'days'       => implode(',', $g->days),
        ], $overrides[$i] ?? []))->all();
    };
});

it('keeps the same rows when the payload is unchanged', function () {
    ($this->put)($this->fresh)->assertOk();
    $before = PricingGroup::where('listing_id', $this->listing->id)->orderBy('id')->get();

    ($this->put)(($this->reposted)())->assertOk();
    $after = PricingGroup::where('listing_id', $this->listing->id)->orderBy('id')->get();

    expect($after->pluck('id')->all())->toBe($before->pluck('id')->all())
        ->and($after->pluck('created_at')->map->toString()->all())
            ->toBe($before->pluck('created_at')->map->toString()->all())
        ->and(PricingGroup::where('listing_id', $this->listing->id)->count())->toBe(2);
});

it('edits a price in place instead of issuing a new id', function () {
    ($this->put)($this->fresh)->assertOk();
    $peak  = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Peak')->sole();
    $quiet = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Quiet')->sole();

    ($this->put)(($this->reposted)([0 => ['price' => 2750]]))->assertOk();

    expect(PricingGroup::find($peak->id))->not->toBeNull()
        ->and((float) PricingGroup::find($peak->id)->price_per_hour)->toBe(2750.0)
        // The group nobody touched is untouched, id included.
        ->and((float) PricingGroup::find($quiet->id)->price_per_hour)->toBe(300.0)
        ->and(PricingGroup::where('listing_id', $this->listing->id)->count())->toBe(2);
});

it('adds only the new group and leaves the existing ids alone', function () {
    ($this->put)($this->fresh)->assertOk();
    $before = PricingGroup::where('listing_id', $this->listing->id)->pluck('id')->sort()->values()->all();

    $payload   = ($this->reposted)();
    $payload[] = ['id' => 'tmp-g-3', 'name' => 'Late', 'price' => 500, 'sort_order' => 2,
                  'start_time' => '20:00', 'end_time' => '22:00', 'days' => '0'];
    ($this->put)($payload)->assertOk();

    $after = PricingGroup::where('listing_id', $this->listing->id)->pluck('id')->sort()->values()->all();

    expect($after)->toHaveCount(3)
        ->and(array_slice($after, 0, 2))->toBe($before);
});

it('deletes only the group the editor dropped', function () {
    ($this->put)($this->fresh)->assertOk();
    $peak  = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Peak')->sole();
    $quiet = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Quiet')->sole();

    ($this->put)([($this->reposted)()[0]])->assertOk();

    expect(PricingGroup::find($peak->id))->not->toBeNull()
        ->and(PricingGroup::find($quiet->id))->toBeNull();
});

it('clears every group when the payload has none', function () {
    ($this->put)($this->fresh)->assertOk();
    ($this->put)([])->assertOk();

    expect(PricingGroup::where('listing_id', $this->listing->id)->count())->toBe(0);
});

it('keeps a stored currency the grid never sends back', function () {
    ($this->put)($this->fresh)->assertOk();
    $peak = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Peak')->sole();
    $peak->update(['currency' => 'qar']);

    ($this->put)(($this->reposted)([0 => ['price' => 450]]))->assertOk();

    expect(PricingGroup::find($peak->id)->currency)->toBe('qar');
});

it('does not let a payload id from another listing edit that listing group', function () {
    $other = Listing::create([
        'name' => 'Court B', 'venue_id' => $this->venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 10, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);
    $theirs = PricingGroup::create([
        'listing_id' => $other->id, 'name' => 'Theirs', 'price_per_hour' => 9999,
        'start_time' => '22:00', 'end_time' => '00:00', 'days' => ['0'], 'sort_order' => 0,
    ]);

    ($this->put)([
        ['id' => (string) $theirs->id, 'name' => 'Hijack', 'price' => 1, 'sort_order' => 0,
         'start_time' => '22:00', 'end_time' => '00:00', 'days' => '0'],
    ])->assertOk();

    $theirs->refresh();
    expect($theirs->name)->toBe('Theirs')
        ->and((float) $theirs->price_per_hour)->toBe(9999.0)
        ->and($theirs->listing_id)->toBe($other->id)
        // The hijack attempt became an ordinary new row on the right listing.
        ->and(PricingGroup::where('listing_id', $this->listing->id)->sole()->name)->toBe('Hijack');
});

/*
 * Two views, one store.
 *
 * The grid used to be its own table, read FIRST when pricing a slot and never
 * re-checked against the group it named — so a square painted before a group's
 * Days were edited kept charging the old price while the group card said
 * otherwise. Painting is now a statement about the group's coverage, folded into
 * its own Start/End/Days, so editing either view writes the same thing.
 */
it('folds a painted square into the group coverage', function () {
    ($this->put)($this->fresh)->assertOk();
    $peak = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Peak')->sole();

    expect($peak->days)->not->toContain('4');   // Peak is Sun+Mon to start with

    // Paint Thursday 22:00 with Peak, exactly as the grid posts it.
    ($this->put)(($this->reposted)(), [
        ['day_of_week' => 4, 'slot_time' => '22:00',
         'pricing_group_id' => (string) $peak->id, 'direct_price' => null],
    ])->assertOk();

    $peak->refresh();
    expect($peak->days)->toContain('4')
        // …and the days it already had are kept, not replaced.
        ->and($peak->days)->toContain('0')
        ->and($peak->days)->toContain('1');

    // The slot now prices from the group, with nothing else stored anywhere.
    $listing = Listing::with('pricingGroups')->find($this->listing->id);
    expect(matchingPricingGroupForSlot($listing, 'thursday', '22:00', '23:00')?->name)->toBe('Peak');
});

it('keeps no second store for the grid', function () {
    ($this->put)($this->fresh, [
        ['day_of_week' => 4, 'slot_time' => '22:00', 'pricing_group_id' => 'tmp-g-1', 'direct_price' => null],
    ])->assertOk();

    expect(\Illuminate\Support\Facades\Schema::hasTable('pricing_cells'))->toBeFalse();
});

it('hands the grid back expanded from the groups', function () {
    ($this->put)($this->fresh)->assertOk();
    $peak = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Peak')->sole();

    $cells = $this->actingAs($this->staff)
        ->withHeaders(['from' => 'admin-web'])
        ->getJson('/api/admin/listings/dashboard/' . $this->listing->id)
        ->assertOk()
        ->json('data.pricing_cells');

    $at = fn ($dow, $slot) => collect($cells)
        ->first(fn ($c) => (int) $c['day_of_week'] === $dow && $c['slot_time'] === $slot);

    // Peak is 22:00-00:00 on Sun+Mon, so those squares come back shaded by it…
    expect($at(0, '22:00')['pricing_group_id'])->toBe((string) $peak->id)
        ->and($at(1, '23:00')['pricing_group_id'])->toBe((string) $peak->id)
        // …and a day it does not cover comes back with nothing on it.
        ->and($at(4, '22:00'))->toBeNull();
});
