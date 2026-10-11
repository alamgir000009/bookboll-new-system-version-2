<?php

/**
 * Soft deletes for countries / states / cities.
 *
 * Nothing in the schema has a foreign key to these three tables — venues.city_id,
 * hosted_games.city_id and communities.city_id are plain integers — so a hard delete
 * here is silent and permanent. deleted_at makes removal reversible.
 *
 * The property that matters most is that a retired row disappears from queries made
 * through the VENDOR model, because that is what the thirty-odd existing files import
 * and what the nnjeim/world package's own routes use. If the scope only applied to
 * App\Models\City, every one of those would keep serving deleted cities.
 */

use App\Models\City as AppCity;
use App\Support\GeoSoftDeletes;
use App\Support\QatarMarketGeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nnjeim\World\Models\City as VendorCity;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->qatar = Country::create([
        'name' => 'Qatar', 'iso2' => 'QA', 'iso3' => 'QAT', 'phone_code' => '974',
        'region' => 'Asia', 'subregion' => 'Western Asia', 'status' => 1,
    ]);
    $state = State::create([
        'name' => 'Ad Dawhah', 'country_id' => $this->qatar->id, 'country_code' => 'QAT',
    ]);

    $this->cities = collect(['Doha', 'Al Wakrah', 'Al Khawr', 'Dukhan'])
        ->mapWithKeys(fn ($name) => [$name => VendorCity::create([
            'name' => $name, 'country_id' => $this->qatar->id,
            'country_code' => 'QAT', 'state_id' => $state->id, 'status' => 1,
        ])]);
});

it('hides a retired city from the vendor model every other file uses', function () {
    AppCity::whereKey($this->cities['Al Wakrah']->id)->delete();

    expect(VendorCity::where('country_id', $this->qatar->id)->pluck('name')->all())
        ->not->toContain('Al Wakrah')
        ->toContain('Doha');

    // Gone from reads, still on disk — that is the whole point.
    expect(DB::table('cities')->where('country_id', $this->qatar->id)->count())->toBe(4);
});

it('keeps the row reachable for restoring', function () {
    AppCity::whereKey($this->cities['Dukhan']->id)->delete();

    expect(AppCity::onlyTrashed()->pluck('name')->all())->toBe(['Dukhan']);

    AppCity::onlyTrashed()->restore();

    expect(VendorCity::where('country_id', $this->qatar->id)->count())->toBe(4);
});

it('lets a caller opt back in to trashed rows explicitly', function () {
    AppCity::whereKey($this->cities['Al Khawr']->id)->delete();

    expect(VendorCity::withoutGlobalScope(GeoSoftDeletes::SCOPE)
        ->where('country_id', $this->qatar->id)->count())->toBe(4);
});

it('drops a retired city out of the Qatar one-market expansion', function () {
    // QatarMarketGeo answers "every city in Qatar" for the catalog. A retired city
    // must not be in that answer, or the filter would widen to places the picker no
    // longer offers.
    expect(QatarMarketGeo::allCityIds())->toHaveCount(4);

    AppCity::whereKey($this->cities['Dukhan']->id)->delete();

    expect(QatarMarketGeo::allCityIds())->toHaveCount(3);
});

it('hides a retired city from the customer picker', function () {
    AppCity::whereKey($this->cities['Al Wakrah']->id)->delete();

    $names = collect($this->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/cities?country_id={$this->qatar->id}&limit=500")
        ->assertOk()->json('data.cities') ?? [])->pluck('name')->all();

    expect($names)->not->toContain('Al Wakrah')->toContain('Doha');
});

it('soft-deletes countries and states too', function () {
    \App\Models\Country::whereKey($this->qatar->id)->delete();
    expect(Country::where('iso2', 'QA')->exists())->toBeFalse();

    \App\Models\Country::onlyTrashed()->restore();
    expect(Country::where('iso2', 'QA')->exists())->toBeTrue();

    \App\Models\State::query()->delete();
    expect(State::count())->toBe(0);
});

/* ─────────── geo:retire-cities ─────────── */

it('retires every city but the ones named', function () {
    $this->artisan('geo:retire-cities', ['country' => 'QA', '--keep' => ['Doha']])
        ->expectsOutputToContain('Retiring : 3 of 4 cities')
        ->assertSuccessful();

    expect(VendorCity::where('country_id', $this->qatar->id)->pluck('name')->all())->toBe(['Doha']);
});

it('refuses when something still points at a city it would retire', function () {
    // The reason the command audits instead of trusting: nothing in the database
    // would stop this delete, and the venue would be left pointing at nothing.
    \App\Models\Venue::create([
        'name' => 'Wakrah Arena', 'country_id' => $this->qatar->id,
        'city_id' => $this->cities['Al Wakrah']->id, 'status' => \App\Models\Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    $this->artisan('geo:retire-cities', ['country' => 'QA', '--keep' => ['Doha']])
        ->expectsOutputToContain('venues.city_id: 1')
        ->assertFailed();

    // Refused means refused: all four still active.
    expect(VendorCity::where('country_id', $this->qatar->id)->count())->toBe(4);
});

it('finds a reference in any table with a city_id, not a remembered list', function () {
    // communities is not a table anyone would think of when retiring a city, which is
    // exactly why the audit reads information_schema instead of a list in the code.
    DB::table('communities')->insert([
        'uuid'              => (string) Str::uuid(),
        'owner_customer_id' => (string) Str::uuid(),
        'name'              => 'Khawr Padel',
        'country_id'        => $this->qatar->id,
        'city_id'           => $this->cities['Al Khawr']->id,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    $this->artisan('geo:retire-cities', ['country' => 'QA', '--keep' => ['Doha']])
        ->expectsOutputToContain('communities.city_id: 1')
        ->assertFailed();
});

it('changes nothing on a dry run', function () {
    $this->artisan('geo:retire-cities', ['country' => 'QA', '--keep' => ['Doha'], '--dry-run' => true])
        ->assertSuccessful();

    expect(VendorCity::where('country_id', $this->qatar->id)->count())->toBe(4);
});

it('will not empty a country by accident', function () {
    $this->artisan('geo:retire-cities', ['country' => 'QA'])
        ->expectsOutputToContain('EVERY city')
        ->assertFailed();

    expect(VendorCity::where('country_id', $this->qatar->id)->count())->toBe(4);
});

it('puts them all back', function () {
    $this->artisan('geo:retire-cities', ['country' => 'QA', '--keep' => ['Doha']])->assertSuccessful();
    $this->artisan('geo:retire-cities', ['country' => 'QA', '--restore' => true])->assertSuccessful();

    expect(VendorCity::where('country_id', $this->qatar->id)->count())->toBe(4);
});
