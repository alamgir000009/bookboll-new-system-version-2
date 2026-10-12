<?php

/**
 * Venue coordinates, typed in by hand.
 *
 * The Edit Location drawer used to show the latitude/longitude as read-only green
 * text: the only way to set them was to drag the map pin. These cover the contract
 * the new inputs rely on — the dashboard hands the stored pair back so the boxes can
 * be filled, and takes a typed pair back.
 *
 * The range check matters more than it looks. Dragging a pin cannot produce an
 * impossible point; typing can — a transposed pair (74.32, 31.48 for Lahore) or a
 * stray digit. `numeric` alone stored it happily, and an impossible venue silently
 * poisons the storefront's distance badge, which is the whole reason those
 * coordinates exist.
 */

use App\Models\User;
use App\Models\Venue;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);

    $country = Country::create([
        'name' => 'Pakistan', 'iso2' => 'PK', 'iso3' => 'PAK', 'phone_code' => '92',
        'region' => 'Asia', 'subregion' => 'Southern Asia', 'status' => 1,
    ]);
    $state = State::create(['name' => 'Punjab', 'country_id' => $country->id, 'country_code' => 'PAK']);
    $this->city = City::create([
        'name' => 'Lahore', 'country_id' => $country->id,
        'country_code' => 'PAK', 'state_id' => $state->id, 'status' => 1,
    ]);
    $this->country = $country;

    $role = Role::findOrCreate('super_admin', 'web');
    foreach (\App\Support\Capabilities::roleMap()['super_admin'] ?? [] as $cap) {
        Permission::findOrCreate($cap, 'web');
    }
    $role->syncPermissions(\App\Support\Capabilities::roleMap()['super_admin'] ?? []);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->admin = User::factory()->create(['status' => User::ACTIVE]);
    $this->admin->assignRole('super_admin');

    $this->venue = Venue::create([
        'name' => 'Lahore venue', 'country_id' => $country->id, 'city_id' => $this->city->id,
        'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=31.4799,74.3266',
        'latitude' => 31.4799160, 'longitude' => 74.3266296,
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
});

function putVenue(array $overrides = [])
{
    return test()->actingAs(test()->admin)->putJson(
        '/api/admin/venues/dashboard/'.test()->venue->id,
        array_merge([
            'name' => 'Lahore venue',
            'city_id' => test()->city->id,
            'country_id' => test()->country->id,
        ], $overrides)
    );
}

it('hands the stored coordinates back so the inputs can be filled', function () {
    // What the drawer reads on open. Without these the boxes come up blank and a
    // save would wipe a perfectly good pin.
    $this->actingAs($this->admin)
        ->getJson("/api/admin/venues/dashboard/{$this->venue->id}")
        ->assertOk()
        ->assertJsonPath('data.location.latitude', 31.479916)
        ->assertJsonPath('data.location.longitude', 74.3266296);
});

it('stores a coordinate typed into the form', function () {
    putVenue(['latitude' => 31.5204, 'longitude' => 74.3587])->assertOk();

    $this->venue->refresh();
    expect((float) $this->venue->latitude)->toBe(31.5204)
        ->and((float) $this->venue->longitude)->toBe(74.3587);
});

it('refuses a latitude off the planet and changes nothing', function () {
    putVenue(['latitude' => 999, 'longitude' => 74.3587])
        ->assertStatus(422)
        ->assertJsonValidationErrors('latitude');

    expect((float) $this->venue->refresh()->latitude)->toBe(31.479916);
});

it('refuses a longitude off the planet and changes nothing', function () {
    putVenue(['latitude' => 31.5204, 'longitude' => -200])
        ->assertStatus(422)
        ->assertJsonValidationErrors('longitude');

    expect((float) $this->venue->refresh()->longitude)->toBe(74.3266296);
});

it('accepts the edges of the range', function () {
    putVenue(['latitude' => -90, 'longitude' => 180])->assertOk();

    $this->venue->refresh();
    expect((float) $this->venue->latitude)->toBe(-90.0)
        ->and((float) $this->venue->longitude)->toBe(180.0);
});

it('still allows a venue with no pin at all', function () {
    putVenue(['latitude' => null, 'longitude' => null])->assertOk();

    $this->venue->refresh();
    expect($this->venue->latitude)->toBeNull()->and($this->venue->longitude)->toBeNull();
});

it('keeps seven decimal places, the precision the form offers', function () {
    // 1e-7 degrees is ~1 cm; truncating to fewer would move a pin across a pitch.
    putVenue(['latitude' => 31.4805748, 'longitude' => 74.3205357])->assertOk();

    $this->venue->refresh();
    expect((float) $this->venue->latitude)->toBe(31.4805748)
        ->and((float) $this->venue->longitude)->toBe(74.3205357);
});
