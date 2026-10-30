<?php

/**
 * Export scope and completeness (readmeAlamgir "Export scope and completeness").
 *
 * "Return all matching authorized rows or an explicit `truncated` signal... do
 * not silently return a current page as a full export." And for customers:
 * "Return `bookings` and `customer_loyalty` for ALL exported customers", so the
 * CSV's aggregates come from the exported rows rather than whichever page
 * happened to be on screen.
 */

use App\Models\Booking;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'customer'] as $r) {
        Role::findOrCreate($r, 'web');
    }
});

function exportAdmin(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('super_admin');

    return $u->fresh();
}

function exportCustomer(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('customer');

    return $u->fresh();
}

function exportVenue(): Venue
{
    return Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=0,0',
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

function customersExport(User $a, array $q = [])
{
    return test()->actingAs($a)->getJson('/api/admin/customers/dashboard?'.http_build_query(
        array_merge(['export' => 1, 'include_lookups' => 1], $q)
    ));
}

it('exports every matching customer, not just one page', function () {
    // More than the 250 the paginator caps a page at.
    collect(range(1, 300))->each(fn () => exportCustomer());

    $res = customersExport(exportAdmin())->assertOk();

    expect($res->json('data.customers.data'))->toHaveCount(300)
        ->and($res->json('data.customers.total'))->toBe(300)
        // Nothing was cut, and it says so.
        ->and($res->json('data.customers.truncated'))->toBeFalse();
});

it('still pages normally when export is not asked for', function () {
    collect(range(1, 60))->each(fn () => exportCustomer());

    $res = test()->actingAs(exportAdmin())
        ->getJson('/api/admin/customers/dashboard?records_per_page=25')->assertOk();

    expect($res->json('data.customers.data'))->toHaveCount(25)
        ->and($res->json('data.customers.total'))->toBe(60)
        ->and($res->json('data.customers.current_page'))->toBe(1);
});

it('carries bookings and loyalty for customers beyond the first page', function () {
    $v = exportVenue();
    $customers = collect(range(1, 260))->map(fn () => exportCustomer());

    // Give the LAST customer a booking — one that a 250-row page would miss.
    $last = $customers->last();
    Booking::create([
        'user_id' => $last->id, 'venue_id' => $v->id, 'date' => '2026-06-10',
        'start_time' => '10:00', 'end_time' => '11:00', 'subtotal' => 100,
        'total_price' => 100, 'currency' => 'QAR', 'status' => 'confirmed',
        'payment_status' => 'fully_paid',
    ]);

    $res = customersExport(exportAdmin())->assertOk();

    $ids = collect($res->json('data.customers.data'))->pluck('id');
    $bookingOwners = collect($res->json('data.bookings'))->pluck('customer_id');

    expect($ids)->toContain((string) $last->id)
        // The lookup covers EVERY exported customer, so the CSV's totals are
        // computed from the same rows it lists.
        ->and($bookingOwners)->toContain((string) $last->id);
});

it('signals truncation rather than handing back a short file that looks complete', function () {
    $svc = new ReflectionClass(App\Services\CustomerService::class);
    $max = $svc->getConstant('EXPORT_MAX');

    // The contract that matters: the cap exists and is reported alongside the
    // flag, so a client can narrow its filters instead of guessing.
    expect($max)->toBeInt()->toBeGreaterThan(0);

    $res = customersExport(exportAdmin())->assertOk();

    expect($res->json('data.customers'))->toHaveKey('truncated')
        ->and($res->json('data.customers.export_max'))->toBe($max);
});

it('keeps the filters the export was asked for', function () {
    $wanted = exportCustomer();
    $wanted->forceFill(['name' => 'Findable Person'])->save();
    collect(range(1, 5))->each(fn () => exportCustomer());

    $res = customersExport(exportAdmin(), ['search' => 'Findable'])->assertOk();

    expect($res->json('data.customers.data'))->toHaveCount(1)
        ->and($res->json('data.customers.data.0.id'))->toBe((string) $wanted->id);
});

it('applies customer type filters to an export the same way as to a page', function () {
    $v = exportVenue();
    $booker = exportCustomer();
    collect(range(1, 3))->each(fn () => exportCustomer());

    Booking::create([
        'user_id' => $booker->id, 'venue_id' => $v->id, 'date' => '2026-06-10',
        'start_time' => '10:00', 'end_time' => '11:00', 'subtotal' => 100,
        'total_price' => 100, 'currency' => 'QAR', 'status' => 'confirmed',
        'payment_status' => 'fully_paid',
    ]);

    $res = customersExport(exportAdmin(), ['customer_types' => ['booking']])->assertOk();

    expect($res->json('data.customers.data'))->toHaveCount(1)
        ->and($res->json('data.customers.data.0.id'))->toBe((string) $booker->id)
        // The echo travels with the export too, so a client can tell an applied
        // filter from an ignored one.
        ->and($res->json('data.customer_type_filter.types'))->toBe(['booking']);
});

it('answers ids_only with every matching customer id across all pages, and nothing else', function () {
    collect(range(1, 60))->each(fn () => exportCustomer());
    $needle = exportCustomer();
    $needle->forceFill(['name' => 'Zed Needle'])->save();

    $all = test()->actingAs(exportAdmin())
        ->getJson('/api/admin/customers/dashboard?records_per_page=25&ids_only=1')->assertOk();

    // The dashboard's "select all matching" header box needs the ids of EVERY
    // page, not the 25 on screen — and this endpoint used to ignore ids_only
    // entirely, which turned the box into "select nothing".
    expect($all->json('data.ids'))->toHaveCount(61)
        ->and($all->json('data.total'))->toBe(61)
        ->and($all->json('data.ids'))->toContain((string) $needle->id)
        ->and($all->json('data.customers'))->toBeNull();

    // The filters still apply.
    $filtered = test()->actingAs(exportAdmin())
        ->getJson('/api/admin/customers/dashboard?ids_only=1&search=Needle')->assertOk();
    expect($filtered->json('data.ids'))->toBe([(string) $needle->id]);
});
