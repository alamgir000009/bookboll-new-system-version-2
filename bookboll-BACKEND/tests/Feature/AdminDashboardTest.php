<?php

/**
 * The admin console's landing page.
 *
 * The figures are the easy half; what these mostly pin is the judgement around
 * them — that cancelled bookings are counted but not valued, that a quiet
 * yesterday produces no percentage at all rather than a meaningless one, and
 * that the health warnings fire on the conditions this project has actually
 * been bitten by.
 */

use App\Livewire\Admin\Dashboard;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\SlotLock;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminConsoleMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    foreach (['super_admin', 'customer'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

function dashboardAdmin(): User
{
    $admin = User::factory()->create(['status' => User::ACTIVE]);
    $admin->assignRole('super_admin');

    return $admin->fresh();
}

function bookableCourt(): Listing
{
    $venue = Venue::create([
        'name' => 'Venue', 'status' => Venue::ACTIVE,
        'owner_user_id' => User::factory()->create()->id,
        'max_bookings_per_slot' => 1, 'min_advance_booking' => 0,
    ]);

    return Listing::create([
        'name' => 'Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 100, 'capacity' => 10, 'slot_duration' => 60,
        'commission_rate' => 15, 'payment_options' => [], 'max_bookings_per_slot' => 1,
    ]);
}

function placeBooking(Listing $court, float $price, string $status = 'confirmed', ?Carbon $createdAt = null): Booking
{
    $booking = Booking::create([
        'user_id' => User::factory()->create()->id,
        'venue_id' => $court->venue_id,
        'listing_id' => $court->id,
        'date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'subtotal' => $price,
        'total_price' => $price,
        'status' => $status,
    ]);

    if ($createdAt) {
        $booking->forceFill(['created_at' => $createdAt])->save();
    }

    return $booking->refresh();
}

/* ---------------------------------------------------------------------------
 | Access
 ---------------------------------------------------------------------------*/

it('is reachable only by a super admin', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $customer->assignRole('customer');

    $this->actingAs($customer)->get(route('dashboard'))->assertRedirect();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->actingAs(dashboardAdmin())->get(route('dashboard'))->assertOk();
});

/* ---------------------------------------------------------------------------
 | Pulse
 ---------------------------------------------------------------------------*/

it('counts today against yesterday', function () {
    $court = bookableCourt();

    placeBooking($court, 100);
    placeBooking($court, 50);
    placeBooking($court, 200, 'confirmed', now()->subDay()->startOfDay()->addHours(9));

    $pulse = app(AdminConsoleMetricsService::class)->pulse();

    expect($pulse['bookings_today'])->toBe(2)
        ->and($pulse['value_today'])->toBe(150.0)
        ->and($pulse['bookings_yesterday'])->toBe(1)
        ->and($pulse['value_yesterday'])->toBe(200.0)
        ->and($pulse['bookings_week'])->toBe(3);
});

it('counts a cancelled booking but does not count its money', function () {
    $court = bookableCourt();

    placeBooking($court, 100, 'confirmed');
    placeBooking($court, 999, 'cancelled');

    $pulse = app(AdminConsoleMetricsService::class)->pulse();

    expect($pulse['bookings_today'])->toBe(2)
        ->and($pulse['cancelled_today'])->toBe(1)
        // The cancelled 999 must not read as trade.
        ->and($pulse['value_today'])->toBe(100.0);
});

it('ignores anything older than the rolling week', function () {
    $court = bookableCourt();

    placeBooking($court, 100, 'confirmed', now()->subDays(30));

    expect(app(AdminConsoleMetricsService::class)->pulse()['bookings_week'])->toBe(0);
});

it('shows no percentage at all when yesterday was zero', function () {
    placeBooking(bookableCourt(), 100);

    Livewire::actingAs(dashboardAdmin())
        ->test(Dashboard::class)
        // "▲ 100%" against nothing would be noise, so no delta is rendered.
        ->assertDontSee('vs yesterday');
});

/* ---------------------------------------------------------------------------
 | Trend
 ---------------------------------------------------------------------------*/

it('returns a zero-filled series so quiet days keep their place', function () {
    $court = bookableCourt();
    placeBooking($court, 100, 'confirmed', now()->subDays(3)->startOfDay()->addHours(10));

    $trend = app(AdminConsoleMetricsService::class)->trend();

    expect($trend)->toHaveCount(AdminConsoleMetricsService::TREND_DAYS)
        ->and(collect($trend)->sum('count'))->toBe(1)
        ->and(collect($trend)->firstWhere('date', now()->subDays(3)->toDateString())['count'])->toBe(1)
        ->and(collect($trend)->firstWhere('date', now()->subDays(2)->toDateString())['count'])->toBe(0)
        // Ends on today, so the chart always reaches the right-hand edge.
        ->and(end($trend)['date'])->toBe(now()->toDateString());
});

/* ---------------------------------------------------------------------------
 | Needs attention
 ---------------------------------------------------------------------------*/

it('counts what is waiting on a human', function () {
    User::factory()->create(['status' => User::PENDING]);
    User::factory()->create(['status' => User::ACTIVE]);

    $attention = app(AdminConsoleMetricsService::class)->attention();

    expect($attention['pending_users'])->toBe(1)
        ->and($attention['failed_jobs'])->toBe(0)
        ->and($attention['queued_jobs'])->toBe(0);
});

it('counts only slot holds that are still blocking', function () {
    $court = bookableCourt();

    SlotLock::create([
        'listing_id' => $court->id, 'date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00', 'end_time' => '11:00:00',
        'session_id' => 'a', 'ip_address' => '203.0.113.1',
        'locked_until' => now()->addMinutes(5),
    ]);

    SlotLock::create([
        'listing_id' => $court->id, 'date' => now()->addDay()->toDateString(),
        'start_time' => '12:00:00', 'end_time' => '13:00:00',
        'session_id' => 'b', 'ip_address' => '203.0.113.2',
        'locked_until' => now()->subMinute(),
    ]);

    expect(app(AdminConsoleMetricsService::class)->attention()['blocking_locks'])->toBe(1);
});

/* ---------------------------------------------------------------------------
 | Health warnings
 ---------------------------------------------------------------------------*/

it('warns about a database cache or session driver', function () {
    $metrics = app(AdminConsoleMetricsService::class);

    $warnings = $metrics->warnings(
        ['cache_driver' => 'database', 'session_driver' => 'database', 'telescope_rows' => 0],
        ['failed_jobs' => 0, 'backup' => ['age_hours' => 1.0, 'file' => 'x.zip', 'bytes' => 1]],
    );

    expect(collect($warnings)->pluck('title')->implode(' | '))
        ->toContain('Cache driver is `database`')
        ->toContain('Session driver is `database`');
});

it('warns when telescope has grown large', function () {
    $warnings = app(AdminConsoleMetricsService::class)->warnings(
        ['cache_driver' => 'file', 'session_driver' => 'file', 'telescope_rows' => 3_012_051],
        ['failed_jobs' => 0, 'backup' => ['age_hours' => 1.0, 'file' => 'x.zip', 'bytes' => 1]],
    );

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['level'])->toBe('danger')
        ->and($warnings[0]['title'])->toContain('3,012,051')
        ->and($warnings[0]['detail'])->toContain('telescope:clear');
});

it('warns about failed jobs and a missing backup', function () {
    $warnings = app(AdminConsoleMetricsService::class)->warnings(
        ['cache_driver' => 'file', 'session_driver' => 'file', 'telescope_rows' => 0],
        ['failed_jobs' => 3, 'backup' => null],
    );

    $titles = collect($warnings)->pluck('title')->implode(' | ');

    expect($titles)->toContain('3 failed job(s)')
        ->toContain('No database backup found');
});

it('says nothing is wrong when everything is healthy', function () {
    $warnings = app(AdminConsoleMetricsService::class)->warnings(
        ['cache_driver' => 'file', 'session_driver' => 'file', 'telescope_rows' => 100],
        ['failed_jobs' => 0, 'backup' => ['age_hours' => 2.0, 'file' => 'x.zip', 'bytes' => 1]],
    );

    expect($warnings)->toBe([]);
});

/* ---------------------------------------------------------------------------
 | The page
 ---------------------------------------------------------------------------*/

it('renders the pulse, the trend and the quick links', function () {
    placeBooking(bookableCourt(), 100);

    Livewire::actingAs(dashboardAdmin())
        ->test(Dashboard::class)
        ->assertSee('Bookings')
        ->assertSee('Booking value')
        ->assertSee('Needs attention')
        ->assertSee('System health')
        ->assertSee('Largest tables')
        ->assertSee('Slot Locks')
        ->assertSee('Active Sessions');
});

it('no longer carries the recent activity strip', function () {
    Livewire::actingAs(dashboardAdmin())
        ->test(Dashboard::class)
        ->assertDontSee('Recent activity');
});

it('caches the system panel and drops it on request', function () {
    $metrics = app(AdminConsoleMetricsService::class);

    $metrics->system();
    expect(Cache::has('admin-console:system-health'))->toBeTrue();

    Livewire::actingAs(dashboardAdmin())
        ->test(Dashboard::class)
        ->call('refreshSystem')
        ->assertSee('System health refreshed');
});
