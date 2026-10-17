<?php

/**
 * Painted prices become groups without the customer paying a different number.
 *
 * Rows in `pricing_cells` that merely named a group held nothing a group did not
 * already say, so the drop migration discards them. A row with its own
 * `direct_price` is real money stored nowhere else — the migration refuses to run
 * while any exist, and this converts them first.
 *
 * The case here is the live one: listing 53 had eight squares painted at 888,
 * scattered across four days and not forming a rectangle, so they cannot be one
 * group. The decomposition is allowed to produce several groups; what it is NOT
 * allowed to do is move a single price by a single riyal.
 */

use App\Models\Listing;
use App\Models\PricingGroup;
use App\Models\Schedule;
use App\Models\Venue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** The live squares: [day_of_week, slot_time] at 888. */
const PAINTED = [[2, '00:00'], [2, '01:00'], [3, '02:00'], [4, '00:00'],
                 [4, '02:00'], [4, '03:00'], [5, '00:00'], [5, '01:00']];

beforeEach(function () {
    // The drop migration has already run, so stand the old table back up to
    // reproduce a server that has not been migrated yet.
    if (! Schema::hasTable('pricing_cells')) {
        Schema::create('pricing_cells', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('listing_id');
            $t->unsignedTinyInteger('day_of_week');
            $t->string('slot_time', 8);
            $t->unsignedBigInteger('pricing_group_id')->nullable();
            $t->decimal('direct_price', 15, 2)->nullable();
            $t->timestamps();
        });
    }

    $venue = Venue::create([
        'name' => 'Lahore', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    $this->listing = Listing::create([
        'name' => 'Court 53', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 10, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    // Listing 53's one real group, which must come through untouched.
    $this->keeper = PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Group 1',
        'start_time' => '22:00', 'end_time' => '00:00', 'price_per_hour' => 2299,
        'days' => ['6'], 'sort_order' => 0,
    ]);

    foreach (PAINTED as [$dow, $slot]) {
        DB::table('pricing_cells')->insert([
            'listing_id' => $this->listing->id, 'day_of_week' => $dow,
            'slot_time' => $slot, 'pricing_group_id' => null, 'direct_price' => 888,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->priceAt = function (int $dow, string $slot) {
        $listing = Listing::with('pricingGroups')->find($this->listing->id);
        $weekday = array_flip(Schedule::DAY_INDEX)[$dow];
        $end     = sprintf('%02d:00', ((int) substr($slot, 0, 2) + 1) % 24);
        $g       = matchingPricingGroupForSlot($listing, $weekday, $slot, $end);

        return $g ? (float) $g->price_per_hour : (float) $listing->base_price;
    };
});

it('writes nothing without --apply', function () {
    $this->artisan('pricing:convert-cells')->assertSuccessful();

    expect(DB::table('pricing_cells')->count())->toBe(count(PAINTED))
        ->and(PricingGroup::where('listing_id', $this->listing->id)->count())->toBe(1);
});

it('keeps every painted slot at exactly the price it was', function () {
    foreach (PAINTED as [$dow, $slot]) {
        expect(($this->priceAt)($dow, $slot))->toBe(250.0);   // base — the cell was doing the work
    }

    $this->artisan('pricing:convert-cells --apply')->assertSuccessful();

    foreach (PAINTED as [$dow, $slot]) {
        expect(($this->priceAt)($dow, $slot))->toBe(888.0, "day $dow $slot must still cost 888");
    }
});

it('leaves hours and days that were never painted alone', function () {
    $this->artisan('pricing:convert-cells --apply')->assertSuccessful();

    // Thursday 01:00 sits in the GAP between the two painted runs on that day.
    expect(($this->priceAt)(4, '01:00'))->toBe(250.0)
        ->and(($this->priceAt)(1, '00:00'))->toBe(250.0)    // Monday, never painted
        ->and(($this->priceAt)(3, '03:00'))->toBe(250.0);   // Wednesday, one hour only
});

it('does not disturb the listing real group', function () {
    $this->artisan('pricing:convert-cells --apply')->assertSuccessful();

    $this->keeper->refresh();
    expect((float) $this->keeper->price_per_hour)->toBe(2299.0)
        ->and($this->keeper->days)->toBe(['6'])
        ->and(($this->priceAt)(6, '22:00'))->toBe(2299.0);
});

it('empties the table so the drop can proceed', function () {
    $this->artisan('pricing:convert-cells --apply')->assertSuccessful();

    expect(DB::table('pricing_cells')->whereNotNull('direct_price')->count())->toBe(0);

    // Which is exactly what the migration guards on.
    $this->artisan('pricing:convert-cells')->expectsOutputToContain('No painted prices left');
});
