<?php

/**
 * Who may cancel a booking.
 *
 * cancel() used to decide with a hardcoded role list written before the capability
 * layer: it named only the LEGACY provider rows, so a `guard` could cancel while
 * provider_admin — the business owner — was refused with "Only the booking creator
 * or staff can cancel."
 *
 * It also had NO venue scoping, so whoever passed that list could cancel any
 * booking on the platform by id. Both are covered here.
 */

use App\Models\Booking;
use App\Models\LoyaltyTier;
use App\Models\User;
use App\Models\Venue;
use App\Support\Capabilities;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** A role carrying the capabilities production gives it. See the note in
 *  BookingPaymentMethodsAccessTest — RefreshDatabase does not run the role seeder. */
function cancelRole(string $name): Role
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

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);
    $bronze = (int) LoyaltyTier::where('slug', 'bronze')->value('id');

    $this->mk = function (string $email, string $role, array $extra = []) use ($bronze): User {
        cancelRole($role);
        $u = User::factory()->create($extra + [
            'email' => $email, 'status' => User::ACTIVE, 'loyalty_tier_id' => $bronze,
        ]);
        $u->assignRole($role);

        return $u->fresh();
    };

    $this->owner = ($this->mk)('owner@test.local', 'provider_admin');
    $this->venue = Venue::create(['name' => 'Owner Venue', 'owner_user_id' => $this->owner->id]);

    $this->rivalOwner = ($this->mk)('rival@test.local', 'provider_admin');
    $this->rivalVenue = Venue::create(['name' => 'Rival Venue', 'owner_user_id' => $this->rivalOwner->id]);

    $this->customer = ($this->mk)('customer@test.local', 'customer');

    $this->booking = Booking::create([
        'user_id'  => $this->customer->id, 'venue_id' => $this->venue->id,
        'date'     => \Carbon\Carbon::tomorrow()->toDateString(),
        'start_time' => '20:00', 'end_time' => '21:00',
        'status'   => BookingStatus::CONFIRMED,
        'subtotal' => 45, 'total_price' => 45,
    ]);
});

function cancelAs($test, User $actor, int $bookingId)
{
    return $test->actingAs($actor)->postJson('/api/admin/bookings/cancel', [
        'booking_id'    => (string) $bookingId,
        'cancel_reason' => 'Testing cancellation',
    ]);
}

it('lets the business owner cancel a booking at their own venue', function () {
    cancelAs($this, $this->owner, $this->booking->id)->assertSuccessful();

    expect($this->booking->refresh()->status)->toBe(BookingStatus::CANCELLED);
});

it('lets the provider ladder cancel at their own venue', function () {
    foreach (['facility_admin', 'provider_supervisor', 'staff'] as $role) {
        $booking = Booking::create([
            'user_id'  => $this->customer->id, 'venue_id' => $this->venue->id,
            'date'     => \Carbon\Carbon::tomorrow()->toDateString(),
            'start_time' => '21:00', 'end_time' => '22:00',
            'status'   => BookingStatus::CONFIRMED,
            'subtotal' => 45, 'total_price' => 45,
        ]);

        $member = ($this->mk)("{$role}@test.local", $role, ['owner_user_id' => $this->owner->id]);

        cancelAs($this, $member, $booking->id)->assertSuccessful("role: {$role}");
        expect($booking->refresh()->status)->toBe(BookingStatus::CANCELLED, "role: {$role}");
    }
});

it('does NOT let another business cancel your booking', function () {
    // Before scoping, any role that passed the role list could cancel any booking.
    $res = cancelAs($this, $this->rivalOwner, $this->booking->id);

    expect($res->json('success'))->toBeFalse();
    expect($this->booking->refresh()->status)->toBe(BookingStatus::CONFIRMED);
});

it('does NOT let a provider_viewer cancel', function () {
    $viewer = ($this->mk)('viewer@test.local', 'provider_viewer', ['owner_user_id' => $this->owner->id]);

    expect(cancelAs($this, $viewer, $this->booking->id)->json('success'))->toBeFalse();
    expect($this->booking->refresh()->status)->toBe(BookingStatus::CONFIRMED);
});

it('still lets a super admin cancel anything', function () {
    $super = ($this->mk)('super@test.local', 'super_admin');

    cancelAs($this, $super, $this->booking->id)->assertSuccessful();
    expect($this->booking->refresh()->status)->toBe(BookingStatus::CANCELLED);
});

it('lets the owner cancel a COMPLETED booking, as an administrator of their venue', function () {
    $this->booking->update(['status' => BookingStatus::COMPLETED]);

    cancelAs($this, $this->owner, $this->booking->id)->assertSuccessful();
    expect($this->booking->refresh()->status)->toBe(BookingStatus::CANCELLED);
});

it('does not let a non-owner staff member cancel a COMPLETED booking', function () {
    $this->booking->update(['status' => BookingStatus::COMPLETED]);
    $member = ($this->mk)('staff2@test.local', 'staff', ['owner_user_id' => $this->owner->id]);

    expect(cancelAs($this, $member, $this->booking->id)->json('success'))->toBeFalse();
    expect($this->booking->refresh()->status)->toBe(BookingStatus::COMPLETED);
});

it('keeps the gate role able to cancel at its own venue', function () {
    // guard holds no manage_bookings but could cancel before this change; kept on
    // purpose for no-shows, now limited to venues it is assigned to.
    $guard = ($this->mk)('guard@test.local', 'guard', ['owner_user_id' => $this->owner->id]);

    cancelAs($this, $guard, $this->booking->id)->assertSuccessful();
    expect($this->booking->refresh()->status)->toBe(BookingStatus::CANCELLED);
});
