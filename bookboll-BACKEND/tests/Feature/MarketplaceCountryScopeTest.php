<?php

/**
 * A visitor in Lahore was shown Qatar venues.
 *
 * The storefront sends `country_iso` (and `country_id` once its picker resolves
 * one) on every catalogue call, but nothing read either for FILTERING — only the
 * city narrowed anything. So whenever the city did not resolve (nothing picked
 * yet, a stale city_id, GPS landing on a town the world table does not carry)
 * the catalogue answered with every country it has. The storefront then labels
 * those prices with the visitor's own currency, which is how a Qatar venue
 * appeared priced in PKR.
 *
 * The city still wins when it resolves: `country_*` follows the VISITOR's
 * location picker, not the place they asked for, so opening /venues/lahore from
 * Doha must stay on Lahore. That line is pinned in
 * CityFilterCountryMismatchTest; these pin the half where there is no city.
 */

use App\Models\Listing;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

uses(RefreshDatabase::class);

function scopeCountry(string $name, string $iso2, string $iso3): Country
{
    return Country::create([
        'name' => $name, 'iso2' => $iso2, 'iso3' => $iso3,
        'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);
}

function scopeCity(Country $country, string $name): City
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

function scopeCourt(City $city, string $venueName, string $courtName): Listing
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
function scopeCourtsFor(string $query): array
{
    return collect(test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/listings?{$query}")
        ->assertOk()
        ->json('data.listings') ?? [])->pluck('name')->all();
}

/** Venue names the marketplace returns for a query string. */
function scopeVenuesFor(string $query): array
{
    return collect(test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/venues?{$query}")
        ->assertOk()
        ->json('data.venues') ?? [])->pluck('name')->all();
}

beforeEach(function () {
    $qatar = scopeCountry('Qatar', 'QA', 'QAT');
    $this->doha = scopeCity($qatar, 'Doha');

    $pakistan = scopeCountry('Pakistan', 'PK', 'PAK');
    $this->pakistanId = $pakistan->id;
    $this->lahore = scopeCity($pakistan, 'Lahore');

    scopeCourt($this->doha, 'Al Waab', 'Doha Court');
    scopeCourt($this->lahore, 'Lahore venue', 'Lahore Court');
});

it('keeps a Pakistani visitor with no city on Pakistani listings', function () {
    // The reported bug: picker has a country but no usable city.
    $courts = scopeCourtsFor('country_iso=PK');

    expect($courts)->toBe(['Lahore Court']);
});

it('filters venue cards by country too', function () {
    expect(scopeVenuesFor('country_iso=PK'))->toBe(['Lahore venue']);
});

it('honours country_id when the picker has resolved one', function () {
    $courts = scopeCourtsFor("country_id={$this->pakistanId}");

    expect($courts)->toBe(['Lahore Court']);
});

it('treats a country it cannot resolve as no geography at all', function () {
    // An ISO the world table does not carry narrows nothing, which is the same
    // answer a crawler gets. Documented deliberately: the alternative is a blank
    // page for a visitor whose picker sent something we do not recognise.
    $courts = scopeCourtsFor('country_iso=ZZ');

    expect($courts)->toContain('Doha Court')->toContain('Lahore Court');
});

it('still lets the resolved city win over the visitor country', function () {
    // Opening /venues/lahore from a Doha-set picker: the city is the answer.
    $courts = scopeCourtsFor("city_id={$this->lahore->id}&city=Lahore&country_iso=QA");

    expect($courts)->toBe(['Lahore Court']);
});

it('leaves a request with no geography at all unfiltered', function () {
    // Crawlers and the sitemap generator send neither, and must still see
    // the whole catalogue.
    $courts = scopeCourtsFor('');

    expect($courts)->toContain('Doha Court')->toContain('Lahore Court');
});

// ── Currency on the card ──────────────────────────────────────────────────────
// The other half of the same bug: once the right venues come back, the price
// beside them still has to wear the venue's own currency rather than whatever
// the visitor's picker says.

/** The first listing card the marketplace returns for a query string. */
function scopeFirstCard(string $query): array
{
    return collect(test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/listings?{$query}")
        ->assertOk()
        ->json('data.listings') ?? [])->first() ?? [];
}

it('labels a card with the currency of its own country', function () {
    DB::table('currencies')->insert([
        ['country_id' => $this->pakistanId, 'name' => 'Pakistani Rupee', 'code' => 'PKR', 'symbol' => 'PKRs', 'symbol_native' => '₨'],
    ]);

    expect(scopeFirstCard('country_iso=PK')['currency'] ?? null)->toBe('PKR');
});

it('prefers the venue currency override over its country', function () {
    DB::table('currencies')->insert([
        ['country_id' => $this->pakistanId, 'name' => 'Pakistani Rupee', 'code' => 'PKR', 'symbol' => 'PKRs', 'symbol_native' => '₨'],
    ]);
    Venue::where('name', 'Lahore venue')->update(['currency_override' => 'USD']);

    expect(scopeFirstCard('country_iso=PK')['currency'] ?? null)->toBe('USD');
});

it('falls back to the configured platform default, not a hardcoded one', function () {
    // Nothing to resolve: no override, and no currency row for the country. The
    // answer has to come from the setting rather than from a constant left over
    // from the UAE-only days — the storefront no longer supplies one at all, so
    // whatever this returns is what the card will wear.
    Setting::updateOrCreate(['key' => 'platform_default_currency'], ['value' => 'QAR']);

    expect(scopeFirstCard('country_iso=PK')['currency'] ?? null)->toBe('QAR');
});

it('puts the resolved currency on the venue detail payload too', function () {
    DB::table('currencies')->insert([
        ['country_id' => $this->pakistanId, 'name' => 'Pakistani Rupee', 'code' => 'PKR', 'symbol' => 'PKRs', 'symbol_native' => '₨'],
    ]);
    $venue = Venue::where('name', 'Lahore venue')->first();

    // currency_override is null here, which is exactly the case that used to
    // leave the detail page guessing from the picker.
    $payload = test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/venues/{$venue->id}")
        ->assertOk()
        ->json('data.facility.location');

    expect($payload['currency_override'])->toBeNull()
        ->and($payload['currency'])->toBe('PKR');
});
