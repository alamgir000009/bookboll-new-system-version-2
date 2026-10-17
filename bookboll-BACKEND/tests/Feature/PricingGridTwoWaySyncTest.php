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

it('reflects a grid paint in the group card', function () {
    $g2 = PricingGroup::where('name', 'Group 2')->sole();
    $card = [
        'id' => (string) $g2->id, 'name' => 'Group 2', 'price' => 275, 'sort_order' => 1,
        'start_time' => '22:00', 'end_time' => '00:00', 'days' => '1,2,3,6,0',
    ];

    // Paint Thursday 22:00 with Group 2 — a day its card does not list.
    ($this->save)([$card], [[
        'day_of_week' => 4, 'slot_time' => '22:00',
        'pricing_group_id' => (string) $g2->id, 'direct_price' => null,
    ]])->assertOk();

    $g2->refresh();
    expect($g2->days)->toContain('4')
        ->and($g2->days)->toContain('0');   // and keeps what the card already had
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
