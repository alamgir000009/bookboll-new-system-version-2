<?php

/**
 * Who gets told about activity on a venue.
 *
 * venueNotificationRecipientIds() is the single recipient list behind every
 * channel — OneSignal push and the Pusher refresh/bell both call it. It was
 * written before the SaaS roles existed and still resolved only the legacy
 * set (super_admin/admin, plus senior_employee/junior_employee via the
 * user_venue pivot), so on a provider-operated venue it returned just the
 * platform admins: the business owner and their whole team were told nothing,
 * and no notification ever reached them.
 *
 * Two things made the owner invisible rather than one. The role list was
 * stale, AND an owner is never pinned (AccessScope::pinsApply excludes
 * provider_admin), so the pivot lookup could never have found them even with
 * the role added — they have to be reached through venues.owner_user_id.
 *
 * These pin the boundary in both directions: the business hears about its own
 * venues, and never about anybody else's.
 */

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

/** A role carrying the capabilities production gives it — RefreshDatabase
 *  does not run the role seeder. Same helper as the other access tests. */
function notifyRole(string $name): Role
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
        notifyRole($role);
        $u = User::factory()->create($extra + [
            'email' => $email, 'status' => User::ACTIVE, 'loyalty_tier_id' => $bronze,
        ]);
        $u->assignRole($role);

        return $u->fresh();
    };

    $this->owner = ($this->mk)('owner@notify.local', 'provider_admin');
    $this->venue = Venue::create(['name' => 'Owner Venue', 'owner_user_id' => $this->owner->id]);
    $this->otherVenue = Venue::create(['name' => 'Second Venue', 'owner_user_id' => $this->owner->id]);

    $this->rivalOwner = ($this->mk)('rival@notify.local', 'provider_admin');
    $this->rivalVenue = Venue::create(['name' => 'Rival Venue', 'owner_user_id' => $this->rivalOwner->id]);
});

it('tells the business owner about their own venue', function () {
    // The regression: an owner is never pinned, so the old pivot-only lookup
    // could not see them however the role list was written.
    expect(venueNotificationRecipientIds($this->venue->id))
        ->toContain($this->owner->id);
});

it('tells an unpinned provider team member about every venue in the business', function () {
    $manager = ($this->mk)('manager@notify.local', 'facility_admin', [
        'owner_user_id' => $this->owner->id,
    ]);

    expect(venueNotificationRecipientIds($this->venue->id))->toContain($manager->id)
        ->and(venueNotificationRecipientIds($this->otherVenue->id))->toContain($manager->id);
});

it('narrows a pinned team member to the venues they are pinned to', function () {
    $staff = ($this->mk)('staff@notify.local', 'staff', [
        'owner_user_id' => $this->owner->id,
    ]);
    $staff->venues()->sync([$this->venue->id]);
    $staff->forceFill(['assigned_location_ids' => [$this->venue->id]])->save();

    expect(venueNotificationRecipientIds($this->venue->id))->toContain($staff->id)
        ->and(venueNotificationRecipientIds($this->otherVenue->id))->not->toContain($staff->id);
});

it('never tells another business about a venue it does not operate', function () {
    $rivalStaff = ($this->mk)('rivalstaff@notify.local', 'facility_admin', [
        'owner_user_id' => $this->rivalOwner->id,
    ]);

    $recipients = venueNotificationRecipientIds($this->venue->id);

    expect($recipients)->not->toContain($this->rivalOwner->id)
        ->and($recipients)->not->toContain($rivalStaff->id);
});

it('still tells a legacy pinned employee, as it always did', function () {
    $legacy = ($this->mk)('legacy@notify.local', 'senior_employee', [
        'owner_user_id' => $this->owner->id,
    ]);
    $legacy->venues()->sync([$this->venue->id]);

    expect(venueNotificationRecipientIds($this->venue->id))->toContain($legacy->id);
});

it('always tells a global admin, venue or not', function () {
    $superAdmin = ($this->mk)('super@notify.local', 'super_admin');

    expect(venueNotificationRecipientIds($this->venue->id))->toContain($superAdmin->id)
        ->and(venueNotificationRecipientIds(null))->toContain($superAdmin->id);
});

it('does not tell a provider about a venue when no venue is given', function () {
    // A null venue is a platform-wide event — only the global admins.
    expect(venueNotificationRecipientIds(null))->not->toContain($this->owner->id);
});

it('returns each recipient once', function () {
    $staff = ($this->mk)('dupe@notify.local', 'staff', [
        'owner_user_id' => $this->owner->id,
    ]);
    // Pinned through BOTH sources, and inside the owner's business — three
    // different branches can reach this user.
    $staff->venues()->sync([$this->venue->id]);
    $staff->forceFill(['assigned_location_ids' => [$this->venue->id]])->save();

    $recipients = venueNotificationRecipientIds($this->venue->id);

    expect($recipients)->toEqual(array_values(array_unique($recipients)));
});
