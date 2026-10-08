<?php

/**
 * Who may record a payment as already taken.
 *
 * GET /api/admin/bookings/payment-methods returns either the full set or just
 * "skip / pay on arrival", decided by role in BookingService::getPaymentMethods().
 * The admin mobile app renders exactly what comes back, which is why the same
 * screen shows seven options to one login and two to another.
 *
 * The business owner used to fall in the restricted group, ranking below the legacy
 * senior_employee role; these tests pin the split so it cannot drift back.
 */

use App\Models\LoyaltyTier;
use App\Models\User;
use App\Support\Capabilities;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);
    $this->bronze = (int) LoyaltyTier::where('slug', 'bronze')->value('id');
});

/**
 * Create a role carrying the capabilities production gives it.
 *
 * 2026_09_07_000006_seed_capability_permissions attaches these, but it SKIPS roles
 * that do not exist yet — and the legacy rows (senior_employee, admin, guard,
 * junior_employee) are created by RolePermissionTableSeeder, which RefreshDatabase
 * does not run. Without this a legacy role would come up with zero capabilities and
 * be refused by the route's capability gate before reaching the method under test.
 */
function roleWithCapabilities(string $name): Role
{
    $role = Role::findOrCreate($name, 'web');
    $caps = Capabilities::roleMap()[$name] ?? [];

    foreach ($caps as $cap) {
        Permission::findOrCreate($cap, 'web');
    }
    $role->syncPermissions($caps);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role;
}

/** Payment method values this role is offered. */
function methodsFor($test, string $role): array
{
    roleWithCapabilities($role);

    $user = User::factory()->create([
        'email'           => $role . '@test.local',
        'status'          => User::ACTIVE,
        'loyalty_tier_id' => $test->bronze,
    ]);
    $user->assignRole($role);

    return collect(
        $test->actingAs($user->fresh())
            ->getJson('/api/admin/bookings/payment-methods')
            ->json('data.payment_methods') ?? []
    )->pluck('value')->sort()->values()->all();
}

$restricted = ['cash_on_arrival', 'payment_skipped'];

$full = [
    'cash_on_arrival', 'online_payment', 'paid_cash', 'paid_cheque',
    'paid_credit_card', 'paid_wire_transfer', 'payment_skipped',
];

it('gives the business owner the full set', function () use ($full) {
    // The point of this change: an owner can record a payment taken at their venue.
    expect(methodsFor($this, 'provider_admin'))->toBe($full);
});

it('still gives platform admins the full set', function () use ($full) {
    expect(methodsFor($this, 'super_admin'))->toBe($full);
    expect(methodsFor($this, 'admin'))->toBe($full);
});

it('keeps the legacy senior_employee role working', function () use ($full) {
    expect(methodsFor($this, 'senior_employee'))->toBe($full);
});

it('keeps front-desk tiers on skip / pay-on-arrival only', function () use ($restricted) {
    // Deliberate: recording money as already received is a cash-handling control.
    foreach (['facility_admin', 'provider_supervisor', 'staff', 'provider_viewer'] as $role) {
        expect(methodsFor($this, $role))->toBe($restricted, "role: {$role}");
    }
});

it('never offers deposit_remaining_on_arrival, which this screen cannot take', function () {
    expect(methodsFor($this, 'provider_admin'))->not->toContain('deposit_remaining_on_arrival');
});

it('refuses an unauthenticated caller', function () {
    $this->getJson('/api/admin/bookings/payment-methods')->assertUnauthorized();
});

it('offers the owner every method the booking validator accepts', function () {
    // The list is useless if the API would reject what it offers.
    $accepted = app(App\Services\BookingService::class)
        ->customerBookingValidationRules()['payment_method'];

    foreach (methodsFor($this, 'provider_admin') as $method) {
        expect($accepted)->toContain($method);
    }
});
