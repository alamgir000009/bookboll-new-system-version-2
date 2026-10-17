<?php

/**
 * Sunday survives a pricing-group save.
 *
 * The admin pricing grid posts `days` as a comma string of weekday INDICES, and
 * Sunday is 0. ListingDashboardService::normalizeGroupDays() ran the parts
 * through `array_filter()` with no callback, which drops every falsy value — so
 * the string "0" went in and never came out. A group the admin had set for
 * Sunday was stored without it, the Sunday slot silently fell through to
 * whichever other group happened to cover that hour (or to base_price), and the
 * save reported success the whole time.
 *
 * Two holes made it invisible: nothing validated `days`, and the editor keeps
 * showing the day pills it posted, not the ones that came back.
 */

use App\Models\Listing;
use App\Models\PricingGroup;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Venue;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    $this->staff = User::factory()->create();
    $this->staff->assignRole('admin');

    $this->venue = Venue::create([
        'name' => 'Sunday Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    $this->listing = Listing::create([
        'name' => 'Court A', 'venue_id' => $this->venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 10, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    foreach (['sunday', 'monday', 'thursday'] as $day) {
        foreach ([['venue_id' => $this->venue->id], ['listing_id' => $this->listing->id]] as $owner) {
            Schedule::create($owner + [
                'day' => $day, 'is_open' => true,
                'start_time' => '18:00', 'end_time' => '00:00',
            ]);
        }
    }

    // What the grid posts: a peak group on Sun/Mon only, and a cheaper group that
    // covers the same hour on every day — the one Sunday used to fall through to.
    $this->save = function (string $peakDays) {
        return $this->actingAs($this->staff)
            ->withHeaders(['from' => 'admin-web'])
            ->putJson('/api/admin/listings/dashboard/' . $this->listing->id, [
                'name'           => 'Court A',
                'location_id'    => $this->venue->id,
                'description'    => 'x',
                'base_price'     => 250,
                'slot_duration'  => 60,
                'pricing_groups' => [
                    [
                        'name' => 'Peak', 'price' => 2750, 'sort_order' => 0,
                        'start_time' => '22:00', 'end_time' => '00:00', 'days' => $peakDays,
                    ],
                    [
                        'name' => 'Off-peak', 'price' => 300, 'sort_order' => 1,
                        'start_time' => '22:00', 'end_time' => '00:00', 'days' => '0,1,2,3,4,5,6',
                    ],
                ],
                'pricing_cells'  => [],
            ]);
    };
});

it('keeps Sunday in a group saved for Sunday', function () {
    ($this->save)('0,1')->assertOk();

    $peak = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Peak')->sole();

    expect($peak->days)->toContain('0')
        ->and($peak->days)->toContain('1')
        ->and((float) $peak->price_per_hour)->toBe(2750.0);
});

it('prices the Sunday 22:00 slot from the Sunday group, not the all-days fallback', function () {
    ($this->save)('0,1')->assertOk();

    $listing = Listing::with('pricingGroups')->find($this->listing->id);

    foreach (['sunday', 'monday'] as $day) {
        $group = matchingPricingGroupForSlot($listing, $day, '22:00', '23:00');
        expect($group?->name)->toBe('Peak', "$day 22:00 should use the peak group");
        expect((float) $group->price_per_hour)->toBe(2750.0);
    }

    // Thursday was never in the peak group, so it still takes the cheaper one.
    $thu = matchingPricingGroupForSlot($listing, 'thursday', '22:00', '23:00');
    expect($thu?->name)->toBe('Off-peak');
});

it('treats a group saved with no days as covering no day', function () {
    ($this->save)('')->assertOk();

    $peak = PricingGroup::where('listing_id', $this->listing->id)->where('name', 'Peak')->sole();
    expect($peak->days)->toBe([]);

    // …and must not be silently widened to Mon-Fri, which is what the old
    // fallback did to every group the grid posted with nothing selected.
    $listing = Listing::with('pricingGroups')->find($this->listing->id);
    expect(matchingPricingGroupForSlot($listing, 'monday', '22:00', '23:00')?->name)->toBe('Off-peak');
});

/*
 * Overlapping groups: the tiebreak must be honoured, not merely attempted.
 *
 * matchingPricingGroupForSlot() ranked candidates with `sortBy([fn, fn, fn])`,
 * but Collection::sortByMany() invokes a callable comparison as `$prop($a, $b)`
 * — each closure is taken as a whole comparator. Returning `$a`'s sort_order
 * made any non-zero value mean "$a is greater", so the group the admin had put
 * FIRST lost to the one it was meant to override.
 */
it('prefers the lower sort_order when two groups cover the same hour', function () {
    ($this->save)('0,1,2,3,4,5,6')->assertOk();

    $listing = Listing::with('pricingGroups')->find($this->listing->id);

    foreach (['sunday', 'monday', 'thursday'] as $day) {
        $group = matchingPricingGroupForSlot($listing, $day, '22:00', '23:00');
        expect($group?->name)->toBe('Peak', "$day 22:00 should take the sort_order 0 group");
    }
});

it('prefers the group the slot starts inside over one it merely overlaps', function () {
    $this->actingAs($this->staff)
        ->withHeaders(['from' => 'admin-web'])
        ->putJson('/api/admin/listings/dashboard/' . $this->listing->id, [
            'name' => 'Court A', 'location_id' => $this->venue->id, 'description' => 'x',
            'base_price' => 250, 'slot_duration' => 60,
            'pricing_groups' => [
                // Listed first, so sort_order alone would hand it the 22:00 slot —
                // but that slot only clips this window's front half, it does not
                // start inside it.
                ['name' => 'Half-past', 'price' => 400, 'sort_order' => 0,
                 'start_time' => '22:30', 'end_time' => '00:00', 'days' => '0,1'],
                ['name' => 'Late', 'price' => 900, 'sort_order' => 1,
                 'start_time' => '22:00', 'end_time' => '00:00', 'days' => '0,1'],
            ],
            'pricing_cells' => [],
        ])->assertOk();

    $listing = Listing::with('pricingGroups')->find($this->listing->id);

    expect(matchingPricingGroupForSlot($listing, 'sunday', '22:00', '23:00')?->name)->toBe('Late')
        // 23:00 starts inside BOTH, so there sort_order decides after all.
        ->and(matchingPricingGroupForSlot($listing, 'sunday', '23:00', '00:00')?->name)->toBe('Half-past');
});
