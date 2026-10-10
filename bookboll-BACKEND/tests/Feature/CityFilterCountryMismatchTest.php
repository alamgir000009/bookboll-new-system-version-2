<?php

/**
 * /venues/lahore showed 23 Qatar venues.
 *
 * `country_iso` follows the VISITOR's location picker, not the city in the URL, so a
 * visitor browsing from Doha who opens a Lahore page sends
 * `city_id=<Lahore>&city=Lahore&country_iso=QA`. resolveCityIds() fired its Qatar
 * one-market expansion on the ISO alone, discarded the resolved city and returned
 * every Qatar city id — so the page answered with Al Waab, Al Gharafa and Abu Hamour.
 *
 * The expansion itself is wanted (Doha / Al Wakrah / Lusail are one marketplace) and
 * still has to work, including for a Qatar city the world table does not carry. These
 * pin the line between the two.
 */

use App\Models\Listing;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

uses(RefreshDatabase::class);

function geoCountry(string $name, string $iso2, string $iso3): Country
{
    return Country::create([
        'name' => $name, 'iso2' => $iso2, 'iso3' => $iso3,
        'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);
}

function geoCity(Country $country, string $name): City
{
    $state = State::firstOrCreate(
        ['name' => $country->name.' State', 'country_id' => $country->id],
        ['country_code' => $country->iso3],
    );

    return City::create([
        'name' => $name, 'country_id' => $country->id,
        'country_code' => $country->iso3, 'state_id' => $state->id, 'status' => 1,
    ]);
}

function geoCourt(City $city, string $venueName, string $courtName): Listing
{
    $venue = Venue::create([
        'name' => $venueName, 'country_id' => $city->country_id, 'city_id' => $city->id,
        'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    return Listing::create([
        'name' => $courtName, 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 45, 'capacity' => 4, 'slot_duration' => 1,
        'payment_options' => ['cash_on_arrival' => '0'], 'max_bookings_per_slot' => 1,
    ]);
}

/** Court names the marketplace returns for a query string. */
function courtsFor(string $query): array
{
    return collect(test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/listings?{$query}")
        ->assertOk()
        ->json('data.listings') ?? [])->pluck('name')->all();
}

beforeEach(function () {
    $qatar = geoCountry('Qatar', 'QA', 'QAT');
    $this->doha = geoCity($qatar, 'Doha');
    $this->wakrah = geoCity($qatar, 'Al Wakrah');

    $pakistan = geoCountry('Pakistan', 'PK', 'PAK');
    $this->lahore = geoCity($pakistan, 'Lahore');

    geoCourt($this->doha, 'Al Waab', 'Doha Court');
    geoCourt($this->wakrah, 'Wakrah Arena', 'Wakrah Court');
    geoCourt($this->lahore, 'Lahore venue', 'Lahore Court');
});

it('keeps a Lahore page on Lahore when the visitor is browsing from Qatar', function () {
    // Exactly what the storefront sends for /venues/lahore from a Doha-set picker.
    $courts = courtsFor("city_id={$this->lahore->id}&city=Lahore&country_iso=QA");

    expect($courts)->toBe(['Lahore Court']);
});

it('keeps the Qatar one-market expansion', function () {
    // Asking for Doha still has to surface Al Wakrah: they are one marketplace.
    $courts = courtsFor("city_id={$this->doha->id}&city=Doha&country_iso=QA");

    expect($courts)->toContain('Doha Court')->toContain('Wakrah Court')
        ->and($courts)->not->toContain('Lahore Court');
});

it('expands by name alone for a Qatar city', function () {
    $courts = courtsFor('city=Al Wakrah&country_iso=QA');

    expect($courts)->toContain('Doha Court')->toContain('Wakrah Court');
});

it('still falls back to all of Qatar when the city cannot be resolved', function () {
    // GPS or the picker can land on a town the world table does not carry, or send a
    // stale id. With country_iso=QA and nothing to resolve, the whole market is the
    // right answer — this is the case the ISO check actually exists for.
    $courts = courtsFor('city_id=999999&city=Umm Salal&country_iso=QA');

    expect($courts)->toContain('Doha Court')->toContain('Wakrah Court')
        ->and($courts)->not->toContain('Lahore Court');
});

it('does not expand a resolvable non-Qatar city even by name alone', function () {
    expect(courtsFor('city=Lahore&country_iso=QA'))->toBe(['Lahore Court']);
});

it('leaves a non-Qatar city strict when the ISO agrees', function () {
    expect(courtsFor("city_id={$this->lahore->id}&city=Lahore&country_iso=PK"))->toBe(['Lahore Court']);
});

it('filters venue cards by the same rule', function () {
    // The /venues page reads the venue feed, not the listing feed — same resolver.
    $venues = collect(test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/venues?city_id={$this->lahore->id}&city=Lahore&country_iso=QA")
        ->assertOk()->json('data.venues') ?? [])->pluck('name')->all();

    expect($venues)->toBe(['Lahore venue']);
});
