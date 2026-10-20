<?php

/**
 * A transferred venue showed a dash where its operator's name belongs.
 *
 * The venue payload returned neither business_id nor business_name, so the
 * dashboard could only name an operator by looking the owner's user id up in a
 * list it happens to hold. After a transfer to a business whose owner is not
 * in that list — an ownerless business, for instance — it had nothing to show.
 *
 * readmeAlamgir asks for business_id on venues for exactly this reason.
 */

use App\Models\Business;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'provider_admin'] as $r) {
        Role::findOrCreate($r, 'web');
    }

    $this->admin = User::factory()->create(['status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $this->admin->assignRole('super_admin');
    $this->admin = $this->admin->fresh();
});

/**
 * business_id is deliberately NOT mass-assignable: ownership only moves
 * through the reviewed transfer, which forceFills it. The fixture does the
 * same rather than pretending a plain create() could set it.
 */
function labelVenue(array $over = []): Venue
{
    $businessId = $over['business_id'] ?? null;
    unset($over['business_id']);

    $venue = Venue::create(array_merge([
        'name' => 'Abu Hamor', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ], $over));

    if ($businessId) {
        $venue->forceFill(['business_id' => $businessId])->save();
    }

    return $venue->fresh();
}

function venuePayload(User $actor, Venue $venue): array
{
    Sanctum::actingAs($actor);

    return test()->getJson("/api/admin/venues/dashboard/{$venue->id}")->assertOk()->json('data.location') ?? [];
}

it('names the business a venue was transferred to', function () {
    // The transfer moves a venue to a BUSINESS. That business may have no
    // owner user at all, which is the case the old id lookup could never name.
    $business = Business::create([
        'owner_id' => null, 'name' => 'Bright Future International School',
        'username' => 'bright-'.uniqid(), 'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);

    // An ownerless business is the case the old lookup could never name.
    $venue = labelVenue(['business_id' => $business->id, 'owner_user_id' => null]);

    $data = venuePayload($this->admin, $venue);

    expect($data['business_id'])->toBe((string) $business->id);
    expect($data['business_name'])->toBe('Bright Future International School');
});

it('falls back to the owner for rows saved before business_id existed', function () {
    $owner = User::factory()->create(['name' => 'Legacy Owner', 'status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $owner->assignRole('provider_admin');
    Business::create([
        'owner_id' => $owner->id, 'name' => 'Tariq Bin Ziad Primary School',
        'username' => 'tariq-'.uniqid(), 'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);

    $venue = labelVenue(['owner_user_id' => $owner->id, 'business_id' => null]);

    expect(venuePayload($this->admin, $venue)['business_name'])->toBe('Tariq Bin Ziad Primary School');
});

it('says nothing rather than something wrong for a Bookboll venue', function () {
    $venue = labelVenue(['owner_user_id' => null, 'business_id' => null]);

    $data = venuePayload($this->admin, $venue);

    expect($data['business_id'])->toBeNull();
    expect($data['business_name'])->toBeNull();
    expect($data['owner_user_id'])->toBeNull();
});

it('returns the ids as strings, which is what the dashboard must compare as', function () {
    // The dashboard used === against a numeric list and silently never
    // matched. The convention here is strings; the client normalises.
    $owner = User::factory()->create(['status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $owner->assignRole('provider_admin');
    $venue = labelVenue(['owner_user_id' => $owner->id]);

    $data = venuePayload($this->admin, $venue);

    expect($data['owner_user_id'])->toBeString();
    expect($data['owner_user_id'])->toBe((string) $owner->id);
});
