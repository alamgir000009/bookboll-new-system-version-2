<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Shared court-pool fixture
|--------------------------------------------------------------------------
|
| Several listings can draw on one physical pool of courts - e.g. badminton
| and pickleball sharing 6 courts at Al Sadd. These helpers build that setup
| so the availability rules can be asserted end to end.
|
*/

/**
 * Build a venue with one shared resource pool and the listings that draw on it.
 *
 * @param  int    $poolSize  units in the shared pool (e.g. 6 courts)
 * @param  array  $listings  [key => ['max' => int, 'needs' => int]]; 'needs' omitted means 1.
 *                           A 'needs' of null attaches no resource at all.
 * @return array{venue: \App\Models\Venue, resource: \App\Models\Resource, listings: array, date: string}
 */
function courtPool(int $poolSize, array $listings): array
{
    // Tomorrow sidesteps the "past slot" filter and the next-month gate entirely.
    $date    = \Carbon\Carbon::tomorrow();
    $weekday = strtolower($date->format('l'));

    $venue = \App\Models\Venue::create([
        'name'   => 'Test Venue',
        'status' => 'active',
    ]);

    $resource = \App\Models\Resource::create([
        'name'           => 'Test Court',
        'status'         => 'active',
        'unit_type'      => 'court',
        'total_quantity' => $poolSize,
    ]);

    // The venue must be open across the slots the tests use (20:00-23:00),
    // in the same 30-minute rows production stores.
    foreach (['20:00', '20:30', '21:00', '21:30', '22:00', '22:30'] as $i => $start) {
        \App\Models\Schedule::create([
            'venue_id'   => $venue->id,
            'listing_id' => null,
            'day'        => $weekday,
            'is_open'    => true,
            'start_time' => $start,
            'end_time'   => ['20:30', '21:00', '21:30', '22:00', '22:30', '23:00'][$i],
        ]);
    }

    $built = [];
    foreach ($listings as $key => $spec) {
        $listing = \App\Models\Listing::create([
            'name'                  => 'Listing ' . $key,
            'venue_id'              => $venue->id,
            'status'                => 'active',
            'base_price'            => 45,
            'capacity'              => 4,
            'slot_duration'         => 1,
            'payment_options'       => ['cash_on_arrival' => '0'],
            'max_bookings_per_slot' => $spec['max'],
        ]);

        foreach (['20:00', '20:30', '21:00', '21:30', '22:00', '22:30'] as $i => $start) {
            \App\Models\Schedule::create([
                'venue_id'   => $venue->id,
                'listing_id' => $listing->id,
                'day'        => $weekday,
                'is_open'    => true,
                'start_time' => $start,
                'end_time'   => ['20:30', '21:00', '21:30', '22:00', '22:30', '23:00'][$i],
            ]);
        }

        $needs = array_key_exists('needs', $spec) ? $spec['needs'] : 1;
        if ($needs !== null) {
            $listing->resources()->attach($resource->id, ['quantity' => $needs]);
        }

        $built[$key] = $listing;
    }

    return [
        'venue'    => $venue,
        'resource' => $resource,
        'listings' => $built,
        'date'     => $date->toDateString(),
    ];
}

/**
 * Book a listing into a slot $times over, attaching booking_resource rows the
 * same way BookingService::createSingleBooking() does in production.
 */
function bookCourt(\App\Models\Listing $listing, string $date, string $start, string $end, int $times = 1): void
{
    $user = \App\Models\User::factory()->create();

    for ($i = 0; $i < $times; $i++) {
        $booking = \App\Models\Booking::create([
            'user_id'     => $user->id,
            'venue_id'    => $listing->venue_id,
            'listing_id'  => $listing->id,
            'date'        => $date,
            'start_time'  => $start,
            'end_time'    => $end,
            'status'      => BookingStatus::CONFIRMED,
            'subtotal'    => 45,
            'total_price' => 45,
        ]);

        foreach ($listing->resourceRequirements()->select('resources.id', 'listing_resource.quantity')->get() as $resource) {
            $booking->resourcesAllocated()->attach($resource->id, ['quantity' => $resource->pivot->quantity]);
        }
    }
}

/**
 * The slot payload fetch-time-slots returns for one start time, or null when
 * the slot is not offered at all. $agent distinguishes callers: getSessionId()
 * hashes IP + user agent, so a different agent is a different session.
 */
function slotAt(\App\Models\Listing $listing, string $date, string $start, string $agent = 'session-a'): ?array
{
    $response = test()->withHeaders(['User-Agent' => $agent])
        ->getJson('/api/fetch-time-slots?listing_id=' . $listing->id . '&date=' . $date);

    foreach ($response->json('data.timeSlots') ?? [] as $slot) {
        if ($slot['start_time'] === $start) {
            return $slot;
        }
    }

    return null;
}

/** available_count for a slot; 0 when the slot is withheld entirely. */
function availableCount(\App\Models\Listing $listing, string $date, string $start, string $agent = 'session-a'): int
{
    return slotAt($listing, $date, $start, $agent)['available_count'] ?? 0;
}
