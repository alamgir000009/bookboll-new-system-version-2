<?php

/**
 * Customer product types (readmeAlamgir "Customer product types and advanced
 * filters").
 *
 * Derived from activity, never editable, and scoped to what the viewer may
 * see. The document's acceptance list is pinned below: one customer can hold
 * all three, a block-only customer holds none, `any` and `all` genuinely
 * differ, and an unknown filter is refused rather than silently ignored.
 */

use App\Models\Booking;
use App\Models\ClassProgram;
use App\Models\ClassSubscription;
use App\Models\User;
use App\Models\Venue;
use App\Support\BookingPurpose;
use App\Support\CustomerTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'customer'] as $r) {
        Role::findOrCreate($r, 'web');
    }
});

/** Only users holding the customer role appear on this dashboard. */
function customer(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('customer');

    return $u->fresh();
}

function typesAdmin(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('super_admin');

    return $u->fresh();
}

function typesVenue(): Venue
{
    return Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=0,0',
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

function giveBooking(User $c, Venue $v, array $over = []): Booking
{
    return Booking::create(array_merge([
        'user_id' => $c->id, 'venue_id' => $v->id, 'date' => '2026-05-10',
        'start_time' => '10:00', 'end_time' => '11:00', 'subtotal' => 100,
        'total_price' => 100, 'currency' => 'QAR', 'status' => 'confirmed',
        'payment_status' => 'fully_paid',
    ], $over));
}

function giveMembership(User $c, Venue $v, array $over = []): ClassSubscription
{
    $p = ClassProgram::create([
        'name' => 'Padel Club', 'status' => 'active', 'price' => 100,
        'currency' => 'QAR', 'venue_id' => $v->id,
    ]);

    return ClassSubscription::create(array_merge([
        'uuid' => (string) Str::uuid(), 'program_id' => $p->id,
        'customer_id' => $c->uuid, 'status' => 'active',
        'started_at' => now()->subMonth(), 'first_charge_at' => now()->subMonth(),
        'monthly_amount' => 100, 'currency' => 'QAR',
    ], $over));
}

function giveEvent(User $c, Venue $v, string $status = 'accepted'): void
{
    $gameId = DB::table('hosted_games')->insertGetId([
        'uuid' => (string) Str::uuid(), 'host_customer_id' => (string) Str::uuid(),
        'host_name' => 'Host', 'location_id' => $v->id, 'title' => 'Padel night',
        'game_date' => '2026-05-10', 'start_time' => '18:00', 'end_time' => '20:00',
        'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('game_join_requests')->insert([
        'uuid' => (string) Str::uuid(), 'game_id' => $gameId,
        'joiner_customer_id' => $c->uuid, 'joiner_name' => $c->name,
        'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function customersPage(User $a, array $q = [])
{
    return test()->actingAs($a)->getJson('/api/admin/customers/dashboard?'.http_build_query($q));
}

/** Types for one customer, from the dashboard response. */
function typesOf($res, User $c): array
{
    $row = collect($res->json('data.customers.data'))->firstWhere('id', (string) $c->id);

    // Fail loudly rather than returning [] — otherwise a customer missing from
    // the page makes every "has no types" assertion pass for the wrong reason.
    expect($row)->not->toBeNull("customer {$c->id} was not on the page at all");

    return $row['customer_types'] ?? [];
}

it('gives one customer all three badges', function () {
    $v = typesVenue();
    $c = customer();
    giveBooking($c, $v);
    giveMembership($c, $v);
    giveEvent($c, $v);

    $res = customersPage(typesAdmin())->assertOk();

    expect(typesOf($res, $c))->toContain('booking', 'membership', 'event')
        ->and(collect($res->json('data.customers.data'))->firstWhere('id', (string) $c->id)['customer_types_complete'])
        ->toBeTrue();
});

it('gives a slot-block-only customer no qualifying type', function () {
    $v = typesVenue();
    $c = customer();
    // A provider reserving a slot for them is not them being a customer.
    giveBooking($c, $v, [
        'record_purpose' => BookingPurpose::BLOCK,
        'payment_method' => 'payment_skipped', 'payment_status' => 'payment_skipped',
    ]);

    $res = customersPage(typesAdmin())->assertOk();

    // An authoritative empty array, not an absent field.
    expect(typesOf($res, $c))->toBe([]);
});

it('does not count a cancelled booking or a rejected event join', function () {
    $v = typesVenue();
    $c = customer();
    giveBooking($c, $v, ['status' => 'cancelled']);
    giveEvent($c, $v, 'rejected');

    expect(typesOf(customersPage(typesAdmin())->assertOk(), $c))->toBe([]);
});

it('counts a membership that has since ended, but not one cancelled before it activated', function () {
    $v = typesVenue();
    $ended = customer();
    $abandoned = customer();

    giveMembership($ended, $v, ['status' => 'completed']);
    giveMembership($abandoned, $v, [
        'status' => 'cancelled', 'started_at' => null, 'first_charge_at' => null,
        'cancelled_during_trial_at' => now()->subWeek(),
    ]);

    $res = customersPage(typesAdmin())->assertOk();

    expect(typesOf($res, $ended))->toContain('membership')
        ->and(typesOf($res, $abandoned))->toBe([]);
});

it('treats any as OR and all as AND', function () {
    $v = typesVenue();
    $both = customer();
    $onlyBooking = customer();
    giveBooking($both, $v);
    giveMembership($both, $v);
    giveBooking($onlyBooking, $v);

    $a = typesAdmin();

    $any = customersPage($a, ['customer_types' => ['booking', 'membership'], 'customer_types_match' => 'any'])->assertOk();
    $all = customersPage($a, ['customer_types' => ['booking', 'membership'], 'customer_types_match' => 'all'])->assertOk();

    $anyIds = collect($any->json('data.customers.data'))->pluck('id');
    $allIds = collect($all->json('data.customers.data'))->pluck('id');

    expect($anyIds)->toContain((string) $both->id)
        ->and($anyIds)->toContain((string) $onlyBooking->id)
        // `all` requires BOTH products.
        ->and($allIds)->toContain((string) $both->id)
        ->and($allIds)->not->toContain((string) $onlyBooking->id);
});

it('keeps the page total agreeing with the filtered rows', function () {
    $v = typesVenue();
    $member = customer();
    giveMembership($member, $v);
    collect(range(1, 3))->each(fn () => customer());      // no activity at all

    $res = customersPage(typesAdmin(), ['customer_types' => ['membership']])->assertOk();

    expect($res->json('data.customers.total'))->toBe(1)
        ->and($res->json('data.customers.data'))->toHaveCount(1);
});

it('echoes the filter it actually applied', function () {
    $res = customersPage(typesAdmin(), [
        'customer_types' => ['booking', 'event'], 'customer_types_match' => 'all',
    ])->assertOk();

    expect($res->json('data.customer_type_filter.version'))->toBe(1)
        ->and($res->json('data.customer_type_filter.types'))->toBe(['booking', 'event'])
        ->and($res->json('data.customer_type_filter.match'))->toBe('all');
});

it('refuses an unknown type instead of quietly returning everyone', function () {
    customersPage(typesAdmin(), ['customer_types' => ['booking', 'spaceship']])
        ->assertStatus(422)->assertJsonValidationErrors('customer_types');

    customersPage(typesAdmin(), ['customer_types' => ['booking'], 'customer_types_match' => 'maybe'])
        ->assertStatus(422);
});

it('leaves the list unfiltered when nothing is selected', function () {
    typesVenue();
    collect(range(1, 3))->each(fn () => customer());

    $res = customersPage(typesAdmin())->assertOk();

    expect($res->json('data.customer_type_filter.types'))->toBe([])
        ->and($res->json('data.customers.total'))->toBeGreaterThanOrEqual(3);
});

it('does not treat hosting an event as being an event customer', function () {
    $v = typesVenue();
    $host = customer();

    DB::table('hosted_games')->insert([
        'uuid' => (string) Str::uuid(), 'host_customer_id' => $host->uuid,
        'host_name' => 'Host', 'location_id' => $v->id, 'title' => 'Game',
        'game_date' => '2026-05-10', 'start_time' => '18:00', 'end_time' => '20:00',
        'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Running an event is a different relationship from attending one.
    expect(typesOf(customersPage(typesAdmin())->assertOk(), $host))->toBe([]);
});
