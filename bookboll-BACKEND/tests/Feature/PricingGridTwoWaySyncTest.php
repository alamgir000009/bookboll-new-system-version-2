<?php

/**
 * The group cards and the grid are two views of one store.
 *
 * Dynamic pricing used to live in two tables. `pricing_cells` held one row per
 * painted square naming a group, and it was read FIRST when pricing a slot —
 * without ever re-checking that the group it named still covered that day or
 * hour. Edit a group's Days and the squares went on charging the old price,
 * while the admin's group card said something else entirely. Nothing
 * reconciled them, so the editor and the customer's price could disagree for
 * ever, and on the live site they did.
 *
 * `pricing_groups` is now the only store: the grid is expanded from it on read,
 * and a painted square is folded back into its group's coverage on write.
 */

use App\Models\Listing;
use App\Models\PricingGroup;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    $this->staff = User::factory()->create();
    $this->staff->assignRole('admin');

    $this->venue = Venue::create([
        'name' => 'Al Gharafa', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    $this->listing = Listing::create([
        'name' => '9v9 Full Court', 'venue_id' => $this->venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 18, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    foreach (array_keys(Schedule::DAY_INDEX) as $day) {
        foreach ([['venue_id' => $this->venue->id], ['listing_id' => $this->listing->id]] as $owner) {
            Schedule::create($owner + ['day' => $day, 'is_open' => true,
                'start_time' => '16:00', 'end_time' => '00:00']);
        }
    }

    // Listing 52's real groups, as the live dump holds them.
    foreach ([
        ['Group 1', '19:00', '22:00', 300, ['4', '5'],                0],
        ['Group 2', '22:00', '00:00', 275, ['1', '2', '3', '6', '0'], 1],
        ['Group 3', '00:00', '01:00', 300, ['4', '5'],                2],
        ['Group 4', '22:00', '00:00', 300, ['4', '5'],                3],
        ['Group 5', '19:00', '22:00', 275, ['1', '2', '3', '6'],      4],
    ] as [$name, $s, $e, $price, $days, $sort]) {
        PricingGroup::create([
            'listing_id' => $this->listing->id, 'name' => $name,
            'start_time' => $s, 'end_time' => $e, 'price_per_hour' => $price,
            'days' => $days, 'sort_order' => $sort,
        ]);
    }

    $this->show = fn () => $this->actingAs($this->staff)
        ->withHeaders(['from' => 'admin-web'])
        ->getJson('/api/admin/listings/dashboard/' . $this->listing->id)
        ->assertOk()->json('data');

    $this->save = fn (array $groups, array $cells = []) => $this->actingAs($this->staff)
        ->withHeaders(['from' => 'admin-web'])
        ->putJson('/api/admin/listings/dashboard/' . $this->listing->id, [
            'name' => '9v9 Full Court', 'location_id' => $this->venue->id,
            'description' => 'x', 'base_price' => 250, 'slot_duration' => 60,
            'pricing_groups' => $groups, 'pricing_cells' => $cells,
        ]);
});

it('has only one place dynamic pricing lives', function () {
    expect(Schema::hasTable('pricing_cells'))->toBeFalse();
});

it('prices Sunday from the one group that covers it', function () {
    $listing = Listing::with('pricingGroups')->find($this->listing->id);

    // Group 5 is Mon/Tue/Wed/Sat and Group 4 is Thu/Fri — neither may touch Sunday.
    foreach ([['19:00', '20:00'], ['20:00', '21:00'], ['21:00', '22:00']] as [$slot, $end]) {
        expect(matchingPricingGroupForSlot($listing, 'sunday', $slot, $end))
            ->toBeNull("sunday $slot must fall through to base price");
    }
    foreach (['22:00', '23:00'] as $slot) {
        $end = $slot === '23:00' ? '00:00' : '23:00';
        $g = matchingPricingGroupForSlot($listing, 'sunday', $slot, $end);
        expect($g?->name)->toBe('Group 2')
            ->and((float) $g->price_per_hour)->toBe(275.0);
    }
});

it('draws the grid from the groups, with nothing of its own', function () {
    $cells = collect(($this->show)()['pricing_cells']);
    $at = fn ($dow, $slot) => $cells->first(fn ($c) => (int) $c['day_of_week'] === $dow && $c['slot_time'] === $slot);

    $g2 = PricingGroup::where('name', 'Group 2')->sole();

    expect($at(0, '22:00')['pricing_group_id'])->toBe((string) $g2->id)
        ->and($at(0, '19:00'))->toBeNull()      // Group 5 is not a Sunday group
        ->and($at(4, '19:00')['pricing_group_id'])->toBe((string) PricingGroup::where('name', 'Group 1')->sole()->id)
        // Every square the grid draws names a group; none carries a loose price.
        ->and($cells->whereNotNull('direct_price'))->toBeEmpty();
});

it('reflects a group card edit in the grid', function () {
    $g2 = PricingGroup::where('name', 'Group 2')->sole();

    // Untick Sunday on the card.
    ($this->save)([[
        'id' => (string) $g2->id, 'name' => 'Group 2', 'price' => 275, 'sort_order' => 1,
        'start_time' => '22:00', 'end_time' => '00:00', 'days' => '1,2,3,6',
    ]])->assertOk();

    $cells = collect(($this->show)()['pricing_cells']);
    expect($cells->first(fn ($c) => (int) $c['day_of_week'] === 0 && $c['slot_time'] === '22:00'))->toBeNull()
        ->and($cells->first(fn ($c) => (int) $c['day_of_week'] === 1 && $c['slot_time'] === '22:00'))->not->toBeNull();
});

it('takes a grid paint from the groups the editor posts, never from cells', function () {
    // Repost the whole set as the editor does, with one group changed — a save
    // that posts only one group correctly drops the rest.
    $withGroup = function (string $name, array $patch) {
        return collect(($this->show)()['pricing_groups'])
            ->map(fn ($g) => $g['name'] === $name ? array_replace($g, $patch) : $g)
            ->values()->all();
    };

    // Painting Thursday in the grid rewrites the GROUP in the editor, which then
    // posts the group with Thursday on it. That is the only channel.
    ($this->save)($withGroup('Group 2', ['days' => '1,2,3,6,0,4']))->assertOk();
    $g2 = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Group 2')->sole();
    expect($g2->days)->toContain('4')->and($g2->days)->toContain('0');

    // Cells on their own change nothing: a stray Thursday cell naming Group 1
    // does not put Thursday on Group 1.
    $g1 = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Group 1')->sole();
    ($this->save)($withGroup('Group 1', ['days' => '5']), [[
        'day_of_week' => 4, 'slot_time' => '19:00', 'pricing_group_id' => (string) $g1->id, 'direct_price' => null,
    ]])->assertOk();
    expect($g1->refresh()->days)->toBe(['5']);
});

it('survives a full round trip without drifting', function () {
    $before = collect(($this->show)()['pricing_cells'])
        ->map(fn ($c) => $c['day_of_week'] . '|' . $c['slot_time'] . '|' . $c['pricing_group_id'])
        ->sort()->values()->all();

    // Save exactly what the editor was handed, changing nothing.
    $d = ($this->show)();
    ($this->save)($d['pricing_groups'], $d['pricing_cells'])->assertOk();

    $after = collect(($this->show)()['pricing_cells'])
        ->map(fn ($c) => $c['day_of_week'] . '|' . $c['slot_time'] . '|' . $c['pricing_group_id'])
        ->sort()->values()->all();

    expect($after)->toBe($before)
        ->and(PricingGroup::where('listing_id', $this->listing->id)->count())->toBe(5);
});

/*
 * Unticking a day must stick, even though the editor posts back the grid it
 * was handed when it opened.
 *
 * The admin keeps the `pricing_cells` the server derived at open time and
 * posts them back unchanged on save. Those squares were derived from the
 * groups as they were THEN, so after unticking Thursday on a group, the stale
 * Thursday squares still named it — and the save folded Thursday straight back
 * in. The card reopened with Thursday ticked and no error anywhere.
 */
it('keeps a day unticked even when stale grid squares for it are posted back', function () {
    $g2 = PricingGroup::where('name', 'Group 2')->sole();   // Sun,Mon,Tue,Wed,Sat 22:00-00:00

    // What the editor was handed at open time: the grid as derived from the
    // groups as they stood, Saturday squares included.
    $stale = ($this->show)()['pricing_cells'];
    expect(collect($stale)->contains(fn ($c) => (int) $c['day_of_week'] === 6 && $c['pricing_group_id'] === (string) $g2->id))->toBeTrue();

    // Untick Saturday on the card, and post the stale squares back exactly as a
    // real client does.
    ($this->save)([[
        'id' => (string) $g2->id, 'name' => 'Group 2', 'price' => 275, 'sort_order' => 1,
        'start_time' => '22:00', 'end_time' => '00:00', 'days' => '0,1,2,3',
    ]], $stale)->assertOk();

    $g2->refresh();
    expect($g2->days)->not->toContain('6')
        ->and($g2->days)->toContain('0');

    // And the customer is no longer charged Group 2 on Saturday.
    $listing = Listing::with('pricingGroups')->find($this->listing->id);
    expect(matchingPricingGroupForSlot($listing, 'saturday', '22:00', '23:00')?->name)->not->toBe('Group 2');
});
