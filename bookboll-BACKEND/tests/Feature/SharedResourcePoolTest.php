<?php

/**
 * Guards the court-booking rules for listings that share one physical pool.
 *
 * Regression cover for the 2026-09-02 defect: fetch-time-slots reported a
 * listing's full max_bookings_per_slot while a sibling listing already held
 * part of the shared pool. Badminton showed 6 of 6 courts free while
 * pickleball occupied 3 of them.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The reference setup: 6 shared courts, badminton capped at 6 per slot,
 * pickleball capped at 3, each booking consuming one court.
 */
function alSadd(): array
{
    return courtPool(6, [
        'badminton'  => ['max' => 6],
        'pickleball' => ['max' => 3],
    ]);
}

describe('the shared 6-court pool', function () {

    it('offers each listing its own ceiling when nothing is booked', function () {
        ['listings' => $l, 'date' => $date] = alSadd();

        expect(availableCount($l['badminton'], $date, '20:00'))->toBe(6)
            ->and(availableCount($l['pickleball'], $date, '20:00'))->toBe(3);
    });

    it('leaves 3 badminton courts once 3 pickleball courts are booked', function () {
        ['listings' => $l, 'date' => $date] = alSadd();

        bookCourt($l['pickleball'], $date, '20:00', '21:00', times: 3);

        // This is the exact number that regressed: it read 6 before the fix.
        expect(availableCount($l['badminton'], $date, '20:00'))->toBe(3)
            ->and(availableCount($l['pickleball'], $date, '20:00'))->toBe(0);
    });

    it('leaves 1 court for either sport once 5 badminton courts are booked', function () {
        ['listings' => $l, 'date' => $date] = alSadd();

        bookCourt($l['badminton'], $date, '20:00', '21:00', times: 5);

        expect(availableCount($l['badminton'], $date, '20:00'))->toBe(1)
            ->and(availableCount($l['pickleball'], $date, '20:00'))->toBe(1);
    });

    it('leaves 2 pickleball or 5 badminton courts once 1 pickleball court is booked', function () {
        ['listings' => $l, 'date' => $date] = alSadd();

        bookCourt($l['pickleball'], $date, '20:00', '21:00', times: 1);

        expect(availableCount($l['pickleball'], $date, '20:00'))->toBe(2)
            ->and(availableCount($l['badminton'], $date, '20:00'))->toBe(5);
    });

    it('only caps the slot the bookings actually fall in', function () {
        ['listings' => $l, 'date' => $date] = alSadd();

        bookCourt($l['pickleball'], $date, '20:00', '21:00', times: 3);

        expect(availableCount($l['badminton'], $date, '20:00'))->toBe(3)
            ->and(availableCount($l['badminton'], $date, '21:00'))->toBe(6)
            ->and(availableCount($l['badminton'], $date, '22:00'))->toBe(6);
    });

    it('withholds the slot entirely once every court is taken', function () {
        ['listings' => $l, 'date' => $date] = alSadd();

        bookCourt($l['pickleball'], $date, '20:00', '21:00', times: 3);
        bookCourt($l['badminton'], $date, '20:00', '21:00', times: 3);

        expect(slotAt($l['badminton'], $date, '20:00'))->toBeNull()
            ->and(slotAt($l['pickleball'], $date, '20:00'))->toBeNull();
    });

    it('keeps booked and occupied_spots scoped to the listing itself', function () {
        ['listings' => $l, 'date' => $date] = alSadd();

        bookCourt($l['pickleball'], $date, '20:00', '21:00', times: 3);
        $slot = slotAt($l['badminton'], $date, '20:00');

        // The frontend contract: these count this listing only. The shared pool
        // shows up in available_count, not here.
        expect($slot['booked'])->toBe(0)
            ->and($slot['occupied_spots'])->toBe(0)
            ->and($slot['max_bookings_per_slot'])->toBe(6)
            ->and($slot['available_count'])->toBe(3);
    });
});

describe('locks against the pool', function () {

    it('counts another session\'s lock as a consumed court', function () {
        ['listings' => $l, 'date' => $date] = alSadd();

        // Someone else locks all 3 pickleball courts without booking them.
        foreach (['other-1', 'other-2', 'other-3'] as $agent) {
            test()->withHeaders(['User-Agent' => $agent])
                ->postJson('/api/lock-slot', [
                    'listing_id' => $l['pickleball']->id,
                    'date'       => $date,
                    'start_time' => '20:00',
                    'end_time'   => '21:00',
                ])->assertJson(['success' => true]);
        }

        expect(availableCount($l['badminton'], $date, '20:00', 'session-a'))->toBe(3);
    });

    it('never counts a session\'s own lock against itself', function () {
        ['listings' => $l, 'date' => $date] = alSadd();

        test()->withHeaders(['User-Agent' => 'session-a'])
            ->postJson('/api/lock-slot', [
                'listing_id' => $l['badminton']->id,
                'date'       => $date,
                'start_time' => '20:00',
                'end_time'   => '21:00',
            ])->assertJson(['success' => true]);

        expect(availableCount($l['badminton'], $date, '20:00', 'session-a'))->toBe(6);
    });
});

describe('listings that use no resources', function () {

    it('is limited only by its own ceiling, never by a pool', function () {
        ['listings' => $l, 'date' => $date] = courtPool(6, [
            'shared'   => ['max' => 6],
            'standard' => ['max' => 4, 'needs' => null],
        ]);

        bookCourt($l['shared'], $date, '20:00', '21:00', times: 6);

        // The pool is empty, but this listing draws on nothing.
        $slot = slotAt($l['standard'], $date, '20:00');

        expect($slot['available_count'])->toBe(4)
            ->and($slot['available_resources'])->toBeNull();
    });

    it('still subtracts its own bookings', function () {
        ['listings' => $l, 'date' => $date] = courtPool(6, [
            'standard' => ['max' => 4, 'needs' => null],
        ]);

        bookCourt($l['standard'], $date, '20:00', '21:00', times: 3);

        expect(availableCount($l['standard'], $date, '20:00'))->toBe(1);
    });
});

describe('resource arithmetic edge cases', function () {

    it('divides the pool by what each booking consumes', function () {
        // Each booking eats 2 of the 6 courts, so 3 bookings fit despite max 6.
        ['listings' => $l, 'date' => $date] = courtPool(6, [
            'doubles' => ['max' => 6, 'needs' => 2],
        ]);

        expect(availableCount($l['doubles'], $date, '20:00'))->toBe(3);
    });

    it('never reports a negative count when the pool is oversubscribed', function () {
        ['listings' => $l, 'date' => $date] = courtPool(6, [
            'badminton' => ['max' => 6],
            'greedy'    => ['max' => 2, 'needs' => 4],
        ]);

        // 4 + 4 = 8 units drawn from a 6-unit pool.
        bookCourt($l['greedy'], $date, '20:00', '21:00', times: 2);

        expect(availableCount($l['badminton'], $date, '20:00'))->toBe(0);
    });

    it('does not divide by zero when a listing consumes nothing', function () {
        ['listings' => $l, 'date' => $date] = courtPool(6, [
            'free' => ['max' => 5, 'needs' => 0],
        ]);

        expect(availableCount($l['free'], $date, '20:00'))->toBe(5);
    });

    it('lets the tightest of several resources decide, whatever the order', function () {
        ['listings' => $l, 'date' => $date, 'venue' => $venue] = courtPool(6, [
            'badminton' => ['max' => 6],
        ]);

        // A second, scarcer resource: only 2 nets for a listing needing 1 each.
        $nets = \App\Models\Resource::create([
            'name'           => 'Nets',
            'status'         => 'active',
            'unit_type'      => 'net',
            'total_quantity' => 2,
        ]);
        $l['badminton']->resources()->attach($nets->id, ['quantity' => 1]);

        $slot = slotAt($l['badminton'], $date, '20:00');

        // 6 courts but only 2 nets - the nets win, and available_resources
        // reports the minimum rather than whichever resource was iterated last.
        expect($slot['available_count'])->toBe(2)
            ->and($slot['available_resources'])->toBe(2);
    });
});
