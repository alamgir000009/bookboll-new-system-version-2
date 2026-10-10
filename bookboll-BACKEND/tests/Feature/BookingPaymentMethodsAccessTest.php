<?php

/**
 * Who may record a payment as already taken.
 *
 * GET /api/admin/bookings/payment-methods returns either the full set or just
 * "skip / pay on arrival". The admin mobile app renders exactly what comes back,
 * which is why the same screen shows seven options to one login and two to another.
 *
 * The split is the accept_payments CAPABILITY. It used to be a hardcoded role list,
 * which is why the same report kept returning: the owner was missing once, `staff`
 * was missing while its legacy twin senior_employee was present, and a role created
 * through Roles & Permissions could never be on the list at all. These pin the rule
 * by capability so there is nothing left to forget.
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

it('gives every front-desk tier that holds accept_payments the full set', function () use ($full) {
    // The reported bug: a staff member taking payment at the desk saw only "Payment
    // Skipped" and "Cash on Arrival", while senior_employee — the same job, the same
    // capability, just the older role name — saw all seven.
    foreach (['facility_admin', 'provider_supervisor', 'staff'] as $role) {
        expect(methodsFor($this, $role))->toBe($full, "role: {$role}");
    }
});

it('keeps roles without accept_payments on skip / pay-on-arrival only', function () use ($restricted) {
    foreach (['provider_viewer', 'junior_employee', 'guard'] as $role) {
        expect(methodsFor($this, $role))->toBe($restricted, "role: {$role}");
    }
});

it('documents that a custom role is stopped by the ROUTE, not by this rule', function () {
    // A role created through Roles & Permissions can hold accept_payments and this
    // method would serve it the full set — but it never gets that far. Every
    // /api/admin/* route is wrapped in checkRoles:<twelve hardcoded names>, so a role
    // invented later is refused before any capability is consulted.
    //
    // That is a separate gate with a much wider blast radius than payment methods, so
    // it is recorded here rather than changed. If it is ever opened up, this test
    // fails and the capability check below is already correct.
    Permission::findOrCreate(Capabilities::ACCEPT_PAYMENTS, 'web');
    $role = Role::findOrCreate('front_desk_lead', 'web');
    $role->syncPermissions([Capabilities::ACCEPT_PAYMENTS]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create([
        'email'           => 'custom-role@test.local',
        'status'          => User::ACTIVE,
        'loyalty_tier_id' => $this->bronze,
    ]);
    $user->assignRole('front_desk_lead');
    $user = $user->fresh();

    expect($user->hasCapability(Capabilities::ACCEPT_PAYMENTS))->toBeTrue();

    $this->actingAs($user)
        ->getJson('/api/admin/bookings/payment-methods')
        ->assertForbidden();
});

it('narrows one person back when accept_payments is revoked for them', function () use ($restricted) {
    // The cash-handling control is still available, now per PERSON rather than per
    // role: revoke the capability for one staff member and their options narrow
    // without changing anyone else's.
    roleWithCapabilities('staff');

    $user = User::factory()->create([
        'email'              => 'revoked-staff@test.local',
        'status'             => User::ACTIVE,
        'loyalty_tier_id'    => $this->bronze,
        'custom_permissions' => ['revoke' => [Capabilities::ACCEPT_PAYMENTS]],
    ]);
    $user->assignRole('staff');

    $methods = collect($this->actingAs($user->fresh())
        ->getJson('/api/admin/bookings/payment-methods')
        ->json('data.payment_methods') ?? [])->pluck('value')->sort()->values()->all();

    expect($methods)->toBe($restricted);
});

it('offers exactly what the payments gate lets through', function () use ($full, $restricted) {
    // The list and the action must agree. Before, staff could reach mark-booking-paid
    // but were not offered a single "paid" method to get there with.
    foreach (['staff', 'facility_admin', 'provider_supervisor', 'senior_employee', 'provider_admin'] as $role) {
        expect(methodsFor($this, $role))->toBe($full, "role: {$role}");
    }
    foreach (['provider_viewer', 'junior_employee', 'guard'] as $role) {
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

/* ─────────── the API's own gate on accepting a payment ─────────── */

it('lets the roles that hold accept_payments reach mark-booking-paid', function () {
    // The admin app refused the business owner client-side while the API would have
    // allowed them. This pins the API side of that agreement: reaching the route is
    // what matters, so anything other than 403 counts.
    foreach ([
        'provider_admin', 'facility_admin', 'provider_supervisor', 'staff',
        'senior_employee', 'country_admin', 'platform_staff',
    ] as $role) {
        roleWithCapabilities($role);

        $user = User::factory()->create([
            'email'           => 'pay-' . $role . '@test.local',
            'status'          => User::ACTIVE,
            'loyalty_tier_id' => $this->bronze,
        ]);
        $user->assignRole($role);

        $status = $this->actingAs($user->fresh())
            ->postJson('/api/admin/payments/mark-booking-paid', [])
            ->getStatusCode();

        expect($status)->not->toBe(403, "role {$role} was refused by the capability gate");
    }
});

it('still refuses roles without accept_payments', function () {
    foreach (['provider_viewer', 'junior_employee', 'guard'] as $role) {
        roleWithCapabilities($role);

        $user = User::factory()->create([
            'email'           => 'nopay-' . $role . '@test.local',
            'status'          => User::ACTIVE,
            'loyalty_tier_id' => $this->bronze,
        ]);
        $user->assignRole($role);

        $this->actingAs($user->fresh())
            ->postJson('/api/admin/payments/mark-booking-paid', [])
            ->assertForbidden();
    }
});
