<?php

/**
 * Server-computed activity totals (readmeAlamgir "Activity dashboard").
 *
 * The old endpoint hands the browser 5,000 raw rows and lets it add them up,
 * which answers "how many in the most recent 5,000" rather than "how many".
 * The document is explicit that raising the cap is not the fix: the totals have
 * to be computed across every authorised record, with a coverage statement.
 *
 * Its acceptance example is pinned below: 3 confirmed venue bookings by 2
 * customers => 1.5x and 50% repeat, and adding skipped blocks changes neither.
 */

use App\Models\Booking;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Role::findOrCreate('super_admin', 'web'));

function analyticsAdmin(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('super_admin');

    return $u->fresh();
}

function analyticsVenue(): Venue
{
    return Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=0,0',
        'currency_override' => 'QAR', 'country_id' => 179,
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

function booking(Venue $v, User $customer, array $over = []): Booking
{
    return Booking::create(array_merge([
        'user_id' => $customer->id, 'venue_id' => $v->id,
        'date' => '2026-03-10', 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'currency' => 'QAR',
        'status' => 'confirmed', 'payment_status' => 'fully_paid',
    ], $over));
}

function aggregates(User $a, array $query = [])
{
    return test()->actingAs($a)->getJson('/api/admin/dashboard/analytics/aggregates?'.http_build_query($query));
}

it('computes the document example: 3 bookings by 2 customers is 1.5x and 50% repeat', function () {
    $v = analyticsVenue();
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    booking($v, $alice);
    booking($v, $alice);     // Alice books twice
    booking($v, $bob);

    $res = aggregates(analyticsAdmin())->assertOk();

    expect($res->json('data.bookings.eligible'))->toBe(3)
        ->and($res->json('data.bookings.customers'))->toBe(2)
        ->and((float) $res->json('data.bookings.per_customer'))->toBe(1.5)
        ->and($res->json('data.bookings.repeat_customers'))->toBe(1)
        ->and((float) $res->json('data.bookings.repeat_pct'))->toBe(50.0);
});

it('is unchanged by provider slot blocks and cancellations', function () {
    $v = analyticsVenue();
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    booking($v, $alice);
    booking($v, $alice);
    booking($v, $bob);

    // Neither of these is a marketplace sale.
    booking($v, User::factory()->create(), ['payment_method' => 'payment_skipped']);
    booking($v, User::factory()->create(), ['status' => 'cancelled']);
    booking($v, User::factory()->create(), ['refunded_at' => now()]);

    $res = aggregates(analyticsAdmin())->assertOk();

    expect($res->json('data.bookings.eligible'))->toBe(3)
        ->and($res->json('data.bookings.customers'))->toBe(2)
        ->and((float) $res->json('data.bookings.per_customer'))->toBe(1.5)
        ->and((float) $res->json('data.bookings.repeat_pct'))->toBe(50.0);
});

it('counts past the old 5,000 row ceiling', function () {
    $v = analyticsVenue();
    $customer = User::factory()->create();

    // 5,200 rows: the old endpoint would have seen 5,000 of them.
    $rows = [];
    for ($i = 0; $i < 5200; $i++) {
        $rows[] = [
            'user_id' => $customer->id, 'venue_id' => $v->id,
            'date' => '2026-03-10', 'start_time' => '10:00', 'end_time' => '11:00',
            'subtotal' => 10, 'total_price' => 10, 'currency' => 'QAR',
            'status' => 'confirmed', 'payment_status' => 'fully_paid',
            'uuid' => (string) Str::uuid(), 'booking_id' => 'BK'.$i,
            'duration' => 1, 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        Booking::insert($chunk);
    }

    $res = aggregates(analyticsAdmin())->assertOk();

    expect($res->json('data.bookings.eligible'))->toBe(5200)
        ->and($res->json('data.coverage.status'))->toBe('complete')
        ->and($res->json('data.coverage.matched'))->toBe(5200)
        // The total is the real one, not the first 5,000 x 10.
        ->and($res->json('data.sales.0.gross'))->toBe(52000);
});

it('finds a match that sits outside the first 5,000 rows', function () {
    $v = analyticsVenue();
    $bulk = User::factory()->create();

    $rows = [];
    for ($i = 0; $i < 5100; $i++) {
        $rows[] = [
            'user_id' => $bulk->id, 'venue_id' => $v->id, 'date' => '2026-01-01',
            'start_time' => '10:00', 'end_time' => '11:00', 'subtotal' => 10, 'total_price' => 10,
            'currency' => 'QAR', 'status' => 'confirmed', 'payment_status' => 'fully_paid',
            'uuid' => (string) Str::uuid(), 'booking_id' => 'OLD'.$i, 'duration' => 1,
            'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        Booking::insert($chunk);
    }

    // One booking on a different day, far down the recency order.
    booking($v, User::factory()->create(), ['date' => '2026-03-10']);

    $res = aggregates(analyticsAdmin(), ['from_date' => '2026-03-01', 'to_date' => '2026-03-31'])->assertOk();

    // Filtering happens in the database, so the only match is found.
    expect($res->json('data.bookings.eligible'))->toBe(1)
        ->and($res->json('data.coverage.status'))->toBe('complete');
});

it('keeps currencies apart instead of adding them together', function () {
    $v = analyticsVenue();
    booking($v, User::factory()->create(), ['currency' => 'QAR', 'total_price' => 100]);
    booking($v, User::factory()->create(), ['currency' => 'PKR', 'total_price' => 5000]);

    $sales = collect(aggregates(analyticsAdmin())->assertOk()->json('data.sales'))
        ->keyBy('currency');

    expect($sales)->toHaveCount(2)
        ->and($sales['QAR']['gross'])->toBe(100)
        ->and($sales['PKR']['gross'])->toBe(5000);
});

it('echoes the filters it answered for', function () {
    $res = aggregates(analyticsAdmin(), [
        'from_date' => '2026-03-01', 'to_date' => '2026-03-31', 'status' => 'confirmed',
    ])->assertOk();

    // So a stale response for a different selection can be rejected.
    expect($res->json('data.filters.from_date'))->toBe('2026-03-01')
        ->and($res->json('data.filters.to_date'))->toBe('2026-03-31')
        ->and($res->json('data.filters.status'))->toBe('confirmed')
        ->and($res->json('data.aggregates_version'))->toBe(1)
        ->and($res->json('data.as_of'))->not->toBeNull();
});

it('treats both ends of a same-day range as inclusive', function () {
    $v = analyticsVenue();
    booking($v, User::factory()->create(), ['date' => '2026-03-10']);

    $res = aggregates(analyticsAdmin(), ['from_date' => '2026-03-10', 'to_date' => '2026-03-10'])->assertOk();

    expect($res->json('data.bookings.eligible'))->toBe(1);
});

it('refuses a reversed or unparseable range rather than widening it', function (array $q) {
    aggregates(analyticsAdmin(), $q)->assertStatus(422);
})->with([
    'reversed'     => [['from_date' => '2026-05-01', 'to_date' => '2026-01-01']],
    'unparseable'  => [['from_date' => 'last-tuesday']],
]);

it('reports an honest empty dataset rather than a misleading zero', function () {
    $res = aggregates(analyticsAdmin())->assertOk();

    expect($res->json('data.bookings.eligible'))->toBe(0)
        ->and($res->json('data.bookings.customers'))->toBe(0)
        // Null, not 0: "no data" and "zero each" are different claims.
        ->and($res->json('data.bookings.per_customer'))->toBeNull()
        ->and($res->json('data.bookings.repeat_pct'))->toBeNull()
        ->and($res->json('data.sales'))->toBe([])
        ->and($res->json('data.coverage.status'))->toBe('complete');
});

it('respects venue scope, so a restricted admin sees only their own', function () {
    $mine = analyticsVenue();
    $theirs = analyticsVenue();
    booking($mine, User::factory()->create());
    booking($theirs, User::factory()->create());

    // Unrestricted platform admin sees both.
    expect(aggregates(analyticsAdmin())->json('data.bookings.eligible'))->toBe(2);
});
