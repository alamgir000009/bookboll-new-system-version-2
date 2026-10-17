<?php

/**
 * The booking drawer shades slots with the price the customer is actually charged.
 *
 * GET /api/admin/bookings/occupancy returns a weekly pricing grid. mapDashboardPricing()
 * built it by expanding each group's window itself, and three things went wrong:
 *
 *  - `days` was matched against lowercase day NAMES, but the admin pricing grid
 *    stores numeric indices, so groups built there produced no cells at all;
 *  - a group ending at midnight was dropped outright (`end <= start`), which is
 *    the exact shape of a late-evening peak group;
 *  - real `pricing_cells` rows were ignored, so a price painted in the Slot Grid
 *    never showed, and two overlapping groups each claimed the same square.
 *
 * Staff were therefore offered base price on slots the customer pays peak for.
 * It now goes through the same resolvers as the customer-facing slot price.
 */

use App\Models\Listing;
use App\Models\PricingCell;
use App\Models\PricingGroup;
use App\Models\User;
use App\Models\Venue;
use App\Support\Capabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $role = Role::findOrCreate('admin', 'web');
    $caps = Capabilities::roleMap()['admin'] ?? [];
    foreach ($caps as $cap) {
        Permission::findOrCreate($cap, 'web');
    }
    $role->syncPermissions($caps);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->staff = User::factory()->create();
    $this->staff->assignRole('admin');

    $this->venue = Venue::create([
        'name' => 'Drawer Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    $this->listing = Listing::create([
        'name' => 'Court A', 'venue_id' => $this->venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 10, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    $this->grid = function () {
        $res = $this->actingAs($this->staff)
            ->withHeaders(['from' => 'admin-web'])
            ->getJson('/api/admin/bookings/occupancy?listing_id=' . $this->listing->id . '&date=2026-10-19');
        $res->assertOk();

        return $res->json('data.pricing_cells');
    };

    $this->cellAt = fn (array $cells, int $dow, string $slot) => collect($cells)
        ->first(fn ($c) => (int) $c['day_of_week'] === $dow && $c['slot_time'] === $slot);

    /** The group id shading a square, or null if nothing shades it. */
    $this->groupAt = fn (array $cells, int $dow, string $slot) => collect($cells)
        ->first(fn ($c) => (int) $c['day_of_week'] === $dow && $c['slot_time'] === $slot)['pricing_group_id'] ?? null;
});

it('shades a group the admin grid saved with numeric day indices', function () {
    $peak = PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Peak', 'price_per_hour' => 2750,
        'start_time' => '19:00', 'end_time' => '22:00',
        'days' => ['0', '1'],   // what the pricing grid writes: Sun, Mon
        'sort_order' => 0,
    ]);

    $cells = ($this->grid)();

    foreach ([0, 1] as $dow) {
        expect(($this->cellAt)($cells, $dow, '19:00'))->not->toBeNull("day $dow 19:00 should be shaded")
            ->and(($this->cellAt)($cells, $dow, '19:00')['pricing_group_id'])->toBe((string) $peak->id);
    }
    // A day the group does not cover stays unshaded.
    expect(($this->cellAt)($cells, 4, '19:00'))->toBeNull();
});

it('shades a group that runs to midnight', function () {
    $late = PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Late', 'price_per_hour' => 900,
        'start_time' => '22:00', 'end_time' => '00:00', 'days' => ['0'], 'sort_order' => 0,
    ]);

    $cells = ($this->grid)();

    expect(($this->groupAt)($cells, 0, '22:00'))->toBe((string) $late->id)
        ->and(($this->groupAt)($cells, 0, '23:00'))->toBe((string) $late->id)
        // 21:00 is before the window.
        ->and(($this->cellAt)($cells, 0, '21:00'))->toBeNull();
});

it('still shades a group stored with day names', function () {
    $peak = PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Peak', 'price_per_hour' => 500,
        'start_time' => '19:00', 'end_time' => '21:00',
        'days' => ['monday', 'thursday'], 'sort_order' => 0,
    ]);

    $cells = ($this->grid)();

    expect(($this->groupAt)($cells, 1, '19:00'))->toBe((string) $peak->id)
        ->and(($this->groupAt)($cells, 4, '20:00'))->toBe((string) $peak->id)
        ->and(($this->cellAt)($cells, 2, '19:00'))->toBeNull();
});

it('reports a price painted in the slot grid', function () {
    PricingCell::create([
        'listing_id' => $this->listing->id, 'day_of_week' => 1,
        'slot_time' => '09:00', 'pricing_group_id' => null, 'direct_price' => 777,
    ]);

    $cell = ($this->cellAt)(($this->grid)(), 1, '09:00');

    expect($cell)->not->toBeNull()
        ->and((float) $cell['direct_price'])->toBe(777.0)
        ->and($cell['pricing_group_id'])->toBeNull();
});

it('lets a painted cell override the group covering that hour', function () {
    $peak = PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Peak', 'price_per_hour' => 400,
        'start_time' => '19:00', 'end_time' => '22:00', 'days' => ['1'], 'sort_order' => 0,
    ]);
    $other = PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Other', 'price_per_hour' => 650,
        'start_time' => '19:00', 'end_time' => '22:00', 'days' => ['1'], 'sort_order' => 1,
    ]);
    PricingCell::create([
        'listing_id' => $this->listing->id, 'day_of_week' => 1,
        'slot_time' => '20:00', 'pricing_group_id' => $other->id, 'direct_price' => null,
    ]);

    $cells = ($this->grid)();

    expect(($this->cellAt)($cells, 1, '20:00')['pricing_group_id'])->toBe((string) $other->id)
        // The unpainted hours of the same window still follow the tiebreak.
        ->and(($this->cellAt)($cells, 1, '19:00')['pricing_group_id'])->toBe((string) $peak->id);
});

it('emits one cell per square when two groups overlap', function () {
    $peak = PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Peak', 'price_per_hour' => 400,
        'start_time' => '19:00', 'end_time' => '22:00', 'days' => ['1'], 'sort_order' => 0,
    ]);
    PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Wide', 'price_per_hour' => 300,
        'start_time' => '18:00', 'end_time' => '23:00', 'days' => ['1'], 'sort_order' => 1,
    ]);

    $cells = ($this->grid)();

    $atTwenty = collect($cells)->filter(fn ($c) => (int) $c['day_of_week'] === 1 && $c['slot_time'] === '20:00');

    expect($atTwenty)->toHaveCount(1)
        ->and($atTwenty->first()['pricing_group_id'])->toBe((string) $peak->id);
});

it('shades nothing when the listing has no dynamic pricing', function () {
    expect(($this->grid)())->toBe([]);
});
