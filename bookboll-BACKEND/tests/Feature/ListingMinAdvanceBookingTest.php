<?php

/**
 * A listing may require a minimum lead time before a slot can be booked,
 * stored as `listings.min_advance_minutes`. Zero, the default, means a slot can
 * be booked right up to its start.
 *
 * Staff are exempt throughout: the admin console and the admin mobile app take
 * walk-ins and phone bookings for slots starting within minutes.
 *
 * `courtPool` opens its venue tomorrow between 20:00 and 23:00, which is
 * between 20 and 44 hours out whatever the clock says now. Lead times here are
 * chosen to sit either side of that window, so nothing depends on the wall
 * clock, and they exercise what the old past-slot filter could not do: reach
 * beyond today into later dates.
 */

use App\Models\Listing;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Slot start times the given surface is offered for a date. */
function slotStartsFor(Listing $listing, string $date, string $from): array
{
    $response = test()->withHeaders(['from' => $from, 'User-Agent' => 'agent-' . $from])
        ->getJson('/api/fetch-time-slots?listing_id=' . $listing->id . '&date=' . $date);

    return collect($response->json('data.timeSlots') ?? [])->pluck('start_time')->all();
}

/** A listing that needs `$minutes` of notice before a slot may be booked. */
function listingNeedingNotice(int $minutes): array
{
    ['listings' => $l, 'date' => $date] = courtPool(6, ['badminton' => ['max' => 6]]);

    $l['badminton']->update(['min_advance_minutes' => $minutes]);

    return [$l['badminton']->fresh(), $date];
}

describe('the lead time withholds slots that are too soon', function () {

    it('offers every slot when no lead time is set', function () {
        [$listing, $date] = listingNeedingNotice(0);

        expect(slotStartsFor($listing, $date, 'frontend-web'))->toContain('20:00');
    });

    it('hides tomorrow entirely behind a three-day lead time', function () {
        [$listing, $date] = listingNeedingNotice(3 * 24 * 60);

        expect(slotStartsFor($listing, $date, 'frontend-web'))->toBeEmpty();
    });

    it('still offers a slot that clears the lead time', function () {
        // Tomorrow 20:00 is between 20 and 44 hours away whatever the clock
        // says now, so a 12-hour lead time always clears it.
        [$listing, $date] = listingNeedingNotice(12 * 60);

        expect(slotStartsFor($listing, $date, 'frontend-web'))->toContain('20:00');
    });

    it('exempts the admin console and the admin mobile app', function () {
        [$listing, $date] = listingNeedingNotice(3 * 24 * 60);

        expect(slotStartsFor($listing, $date, 'admin-web'))->toContain('20:00')
            ->and(slotStartsFor($listing, $date, 'admin_mobile_app'))->toContain('20:00');
    });
});

describe('the write side refuses a slot inside the lead time', function () {

    it('refuses to lock the slot for a customer but not for staff', function () {
        [$listing, $date] = listingNeedingNotice(3 * 24 * 60);

        $lock = fn(string $from) => test()->withHeaders(['from' => $from, 'User-Agent' => 'ua-' . $from])
            ->postJson('/api/lock-slot', [
                'listing_id' => $listing->id,
                'date'       => $date,
                'start_time' => '20:00',
                'end_time'   => '21:00',
            ]);

        $customer = $lock('frontend-web');
        expect($customer->json('success'))->toBeFalse()
            ->and($customer->json('message'))->toContain('3 days');

        expect($lock('admin-web')->json('success'))->toBeTrue();
    });

    it('reports the slot as unavailable to check-slot-availability', function () {
        [$listing, $date] = listingNeedingNotice(3 * 24 * 60);

        $response = test()->withHeaders(['from' => 'frontend-web'])
            ->postJson('/api/check-slot-availability', [
                'listing_id' => $listing->id,
                'date'       => $date,
                'start_time' => '20:00',
                'end_time'   => '21:00',
            ]);

        expect($response->json('data.available'))->toBeFalse()
            ->and($response->json('data.reason'))->toContain('3 days');
    });
});

describe('the rule is never published to the customer', function () {

    it('keeps the lead time out of the slots payload', function () {
        [$listing, $date] = listingNeedingNotice(150);

        $data = test()->withHeaders(['from' => 'frontend-web'])
            ->getJson('/api/fetch-time-slots?listing_id=' . $listing->id . '&date=' . $date)
            ->json('data');

        // Too-soon slots are simply absent; the customer is not told a rule
        // exists, let alone what it is.
        expect($data)->not->toHaveKey('minAdvanceMinutes')
            ->and($data)->not->toHaveKey('minAdvanceLabel');
    });

    it('keeps the lead time out of the listing detail payload', function () {
        [$listing] = listingNeedingNotice(150);

        $detail = test()->withHeaders(['from' => 'frontend-web'])
            ->getJson('/api/fetch-listing/' . $listing->id)
            ->json('data.listing');

        expect($detail)->not->toHaveKey('min_advance_minutes')
            ->and($detail)->not->toHaveKey('min_advance_label');
    });
});

describe('lead times read as plain english', function () {

    it('formats minutes, hours and days', function () {
        expect(formatLeadTime(1))->toBe('1 minute')
            ->and(formatLeadTime(45))->toBe('45 minutes')
            ->and(formatLeadTime(60))->toBe('1 hour')
            ->and(formatLeadTime(120))->toBe('2 hours')
            ->and(formatLeadTime(90))->toBe('1 hour 30 minutes')
            ->and(formatLeadTime(150))->toBe('2 hours 30 minutes')
            ->and(formatLeadTime(1440))->toBe('1 day')
            ->and(formatLeadTime(4320))->toBe('3 days');
    });
});

describe('a lead time inside one day trims the front of that day', function () {

    /** A listing open round the clock today, in the 30-minute rows production stores. */
    function openAllDayToday(int $minAdvanceMinutes): array
    {
        $date    = Carbon::today();
        $weekday = strtolower($date->format('l'));

        $venue   = \App\Models\Venue::create(['name' => 'All Day', 'status' => 'active']);
        $listing = Listing::create([
            'name'                  => 'Court',
            'venue_id'              => $venue->id,
            'status'                => 'active',
            'base_price'            => 10,
            'capacity'              => 4,
            'slot_duration'         => 1,
            'payment_options'       => ['cash_on_arrival' => '0'],
            'max_bookings_per_slot' => 4,
            'min_advance_minutes'   => $minAdvanceMinutes,
        ]);

        foreach ([null, $listing->id] as $scheduleListingId) {
            \App\Models\Schedule::create([
                'venue_id'   => $venue->id,
                'listing_id' => $scheduleListingId,
                'day'        => $weekday,
                'is_open'    => true,
                'start_time' => '00:00',
                'end_time'   => '23:59',
            ]);
        }

        return [$listing, $date->toDateString()];
    }

    afterEach(fn() => Carbon::setTestNow());

    it('offers 18:00 onwards at 17:00 with an hour of notice', function () {
        Carbon::setTestNow(Carbon::today()->setTime(17, 0));

        [$listing, $date] = openAllDayToday(60);
        $starts           = slotStartsFor($listing, $date, 'frontend-web');

        // 17:00 is inside the hour of notice; 18:00 is exactly on the cutoff.
        expect($starts)->not->toContain('17:00')
            ->and($starts)->toContain('18:00')
            ->and($starts)->toContain('19:00')
            ->and($starts[0])->toBe('18:00');
    });

    it('still drops slots already past when no notice is required', function () {
        Carbon::setTestNow(Carbon::today()->setTime(17, 0));

        [$listing, $date] = openAllDayToday(0);
        $starts           = slotStartsFor($listing, $date, 'frontend-web');

        expect($starts)->not->toContain('16:00')
            ->and($starts)->toContain('18:00')
            ->and($starts[0])->toBe('17:00');
    });
});

describe('recurring dates respect the lead time too', function () {

    /** Dates offered by the Recurring Bookings page for a weekly 20:00 slot. */
    function recurringDatesFor(Listing $listing, string $from): array
    {
        $first = Carbon::tomorrow();

        $response = test()->withHeaders(['from' => $from])
            ->getJson('/api/fetch-recurring-booked-slots?' . http_build_query([
                'listing_id' => $listing->id,
                'start_time' => '20:00',
                'end_time'   => '21:00',
                'date'       => $first->toDateString(),
                'start_date' => $first->toDateString(),
                'end_date'   => $first->copy()->addDays(6)->toDateString(),
            ]));

        return collect($response->json('data.dates') ?? [])->pluck('date')->all();
    }

    it('withholds a date inside the lead time instead of refusing it later', function () {
        [$listing] = listingNeedingNotice(3 * 24 * 60);

        expect(recurringDatesFor($listing, 'frontend-web'))
            ->not->toContain(Carbon::tomorrow()->toDateString());
    });

    it('still offers dates that clear it, and every date to staff', function () {
        [$listing] = listingNeedingNotice(3 * 24 * 60);

        expect(recurringDatesFor($listing, 'frontend-web'))
            ->toContain(Carbon::tomorrow()->addDays(5)->toDateString());

        expect(recurringDatesFor($listing, 'admin-web'))
            ->toContain(Carbon::tomorrow()->toDateString());
    });
});

describe('the admin listing form round-trips the lead time', function () {

    beforeEach(function () {
        \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
        $this->staff = \App\Models\User::factory()->create();
        $this->staff->assignRole('admin');

        $type = \App\Models\SportsType::create([
            'title'     => 'Racket',
            'sub_title' => 'Racket sports',
            'slug'      => 'racket',
        ]);
        $this->category = \App\Models\SportsCategory::create([
            'name'           => 'Badminton',
            'slug'           => 'badminton',
            'sports_type_id' => $type->id,
        ]);
        $this->venue = \App\Models\Venue::create([
            'name'   => 'Form Venue',
            'status' => 'active',
        ]);
    });

    /** The payload ListingForm.vue submits, with whatever it sent for the rule. */
    $submit = function ($test, array $extra) {
        $schedule = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->map(fn($day) => [
                'day'        => $day,
                'is_open'    => true,
                'start_time' => '09:00',
                'end_time'   => '22:00',
            ])->all();

        return $test->actingAs($test->staff)
            ->withHeaders(['from' => 'admin-web'])
            ->postJson('/api/admin/listings', array_merge([
                'name'                    => 'Court A',
                'venue_id'                => $test->venue->id,
                'sports_category_id'      => $test->category->id,
                'description'             => 'A court',
                'base_price'              => 50,
                'max_bookings_per_slot'   => 1,
                'capacity'                => 4,
                'slot_duration'           => 1,
                'online_payment'          => 0,
                'allow_multiple_bookings' => 0,
                'show_on_admin'           => 1,
                'show_on_customer'        => 1,
                'payment_options'         => ['cash_on_arrival' => 1],
                'amenities'               => [],
                'schedule'                => $schedule,
            ], $extra));
    };

    it('stores the listing lead time the form sent', function () use ($submit) {
        $submit($this, ['min_advance_minutes' => 90])->assertOk();

        expect(Listing::latest('id')->first())
            ->min_advance_minutes->toBe(90);
    });

    it('stores no lead time when the form leaves the field blank', function () use ($submit) {
        // Laravel turns "" into null before validation, which must land as 0.
        $submit($this, ['min_advance_minutes' => ''])->assertOk();

        expect(Listing::latest('id')->first())->min_advance_minutes->toBe(0);
    });

    it('stores no lead time when the field is omitted entirely', function () use ($submit) {
        $submit($this, [])->assertOk();

        expect(Listing::latest('id')->first())->min_advance_minutes->toBe(0);
    });

    it('rejects a negative lead time', function () use ($submit) {
        $submit($this, ['min_advance_minutes' => -10])
            ->assertJsonValidationErrors(['min_advance_minutes']);
    });
});
