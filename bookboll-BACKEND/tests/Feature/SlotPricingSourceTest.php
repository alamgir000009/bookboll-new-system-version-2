<?php

/**
 * Every slot says WHY it costs what it costs.
 *
 * /fetch-time-slots carries a compact `pricing` block beside the price so a caller
 * can tell a plain base price from a dynamic group, a painted grid cell or a special
 * day — and name the one that decided it — without a second request.
 *
 * Compact is the requirement, not a detail. The full breakdown repeats the matched
 * group's whole definition, including its seven-entry `days` array, on EVERY slot of
 * EVERY day; the month view asks for thirty days at once.
 */

use App\Models\Listing;
use App\Models\PricingCell;
use App\Models\PricingGroup;
use App\Models\Schedule;
use App\Models\SpecialDay;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->venue = Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    $this->listing = Listing::create([
        'name' => 'Court', 'venue_id' => $this->venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 10, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    // Open 18:00-23:00 every day, on both the venue and the listing.
    $this->date = \Carbon\Carbon::parse('next monday');
    foreach (['venue_id' => $this->venue->id, 'listing_id' => $this->listing->id] as $key => $id) {
        Schedule::create([
            $key => $id, 'day' => strtolower($this->date->format('l')),
            'is_open' => true, 'start_time' => '18:00', 'end_time' => '23:00',
        ]);
    }
});

/** The slot list, keyed by start time. */
function slotsFor(?string $date = null): array
{
    $request = new Illuminate\Http\Request();
    $request->merge(['listing_id' => test()->listing->id, 'date' => $date ?? test()->date->toDateString()]);
    $request->headers->set('from', 'frontend-web');

    $body = json_decode(app(App\Services\ListingService::class)->fetchTimeSlots($request)->getContent(), true);

    return collect($body['data']['timeSlots'] ?? [])->keyBy('start_time')->all();
}

it('marks an ordinary slot as the base price', function () {
    $slot = slotsFor()['18:00'];

    expect((float) $slot['price'])->toBe(250.0)
        ->and($slot['pricing'])->toMatchArray([
            'source' => 'base_price', 'is_dynamic' => false,
            'base_price' => 250, 'source_id' => null, 'label' => null,
        ]);
});

it('names the pricing group that set the price', function () {
    $group = PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Peak', 'color' => '#ef4444',
        'start_time' => '19:00', 'end_time' => '22:00', 'price_per_hour' => 300,
        'days' => ['monday'], 'sort_order' => 0,
    ]);

    $slot = slotsFor()['19:00'];

    expect((float) $slot['price'])->toBe(300.0)
        ->and($slot['pricing'])->toMatchArray([
            'source' => 'pricing_group', 'is_dynamic' => true,
            'base_price' => 250, 'source_id' => $group->id,
            'label' => 'Peak', 'color' => '#ef4444',
        ]);
});

it('names the group even when the grid wrote numeric days', function () {
    // The dashboard grid posts indices (Sunday = 0); the API validates names.
    $group = PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Peak', 'color' => null,
        'start_time' => '19:00', 'end_time' => '22:00', 'price_per_hour' => 300,
        'days' => ['1'], 'sort_order' => 0,
    ]);

    expect(slotsFor()['19:00']['pricing'])->toMatchArray([
        'source' => 'pricing_group', 'source_id' => $group->id, 'label' => 'Peak',
    ]);
});

it('reports a painted grid cell with a direct price', function () {
    $cell = PricingCell::create([
        'listing_id' => $this->listing->id,
        'day_of_week' => (int) $this->date->dayOfWeek,   // Carbon: Sunday = 0, same as the grid
        'slot_time' => '20:00', 'pricing_group_id' => null, 'direct_price' => 410,
    ]);

    $slot = slotsFor()['20:00'];

    expect((float) $slot['price'])->toBe(410.0)
        ->and($slot['pricing'])->toMatchArray([
            'source' => 'pricing_cell', 'is_dynamic' => true,
            'base_price' => 250, 'source_id' => $cell->id,
        ]);
});

it('reports a special day, and lets it beat the group', function () {
    PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Peak', 'color' => null,
        'start_time' => '19:00', 'end_time' => '22:00', 'price_per_hour' => 300,
        'days' => ['monday'], 'sort_order' => 0,
    ]);
    $special = SpecialDay::create([
        'venue_id' => $this->venue->id, 'listing_id' => null,
        'date' => $this->date->toDateString(), 'type' => 'special',
        'description' => 'National Day', 'price_multiplier' => 1.5, 'status' => 'active',
    ]);

    $slot = slotsFor()['19:00'];

    // 300 (the group) x 1.5, not base x 1.5 — the special day is an overlay.
    expect((float) $slot['price'])->toBe(450.0)
        ->and($slot['pricing'])->toMatchArray([
            'source' => 'special_day', 'is_dynamic' => true,
            'source_id' => $special->id, 'label' => 'National Day',
        ]);
});

it('stays small — no group definition repeated on every slot', function () {
    PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Peak', 'color' => '#ef4444',
        'start_time' => '19:00', 'end_time' => '22:00', 'price_per_hour' => 300,
        'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        'sort_order' => 0,
    ]);

    $pricing = slotsFor()['19:00']['pricing'];

    expect(array_keys($pricing))->toBe(['source', 'is_dynamic', 'base_price', 'source_id', 'label', 'color'])
        ->and(strlen(json_encode($pricing)))->toBeLessThan(140);

    // The things a caller can read once off the listing must not be here.
    foreach (['days', 'start_time', 'end_time', 'price_per_hour', 'pricing_group', 'pricing_cell'] as $fat) {
        expect($pricing)->not->toHaveKey($fat);
    }
});

it('adds no queries to the slot list', function () {
    PricingGroup::create([
        'listing_id' => $this->listing->id, 'name' => 'Peak', 'color' => null,
        'start_time' => '19:00', 'end_time' => '22:00', 'price_per_hour' => 300,
        'days' => ['monday'], 'sort_order' => 0,
    ]);

    // pricingGroups and pricingCells are eager-loaded with the listing, so resolving
    // five slots must not go back to the database per slot.
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $slots = slotsFor();
    $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
    \Illuminate\Support\Facades\DB::disableQueryLog();

    expect(count($slots))->toBeGreaterThan(4)
        ->and($queries)->toBeLessThan(20);
});
