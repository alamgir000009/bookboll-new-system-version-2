<?php

/**
 * Slots say which currency the price is in.
 *
 * /fetch-time-slots returned bare numbers and left the caller to work the currency
 * out elsewhere. A Qatar listing was rendering "PKR 250" on the live storefront —
 * same number, wrong money, on a real checkout. The slot list now resolves it the
 * same way a booking does when it is actually charged (venue override, then the
 * venue's country, then the platform default), so the two cannot disagree.
 */

use App\Models\Listing;
use App\Models\Schedule;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function slotCurVenue(array $attrs = []): Venue
{
    return Venue::create($attrs + [
        'name' => 'Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

function slotCurListing(Venue $venue): Listing
{
    $listing = Listing::create([
        'name' => 'Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 10, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    $date = \Carbon\Carbon::parse('next monday');
    foreach ([['venue_id' => $venue->id], ['listing_id' => $listing->id]] as $owner) {
        Schedule::create($owner + [
            'day' => strtolower($date->format('l')), 'is_open' => true,
            'start_time' => '18:00', 'end_time' => '22:00',
        ]);
    }

    return $listing;
}

/** The vendor geography tables ship empty under RefreshDatabase; stand up one country. */
function slotCurCountry(string $iso2, string $name, string $code): int
{
    $id = DB::table('countries')->insertGetId([
        'iso2' => $iso2, 'iso3' => $iso2 . 'X', 'name' => $name, 'status' => 1,
        // Every NOT NULL column on the vendor table, none of which has a default.
        'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia',
    ]);
    DB::table('currencies')->insert([
        'country_id' => $id, 'name' => $name . ' currency', 'code' => $code,
        'precision' => 2, 'symbol' => $code, 'symbol_native' => $code,
        'symbol_first' => 1, 'decimal_mark' => '.', 'thousands_separator' => ',',
    ]);

    return $id;
}

function slotCurFetch(Listing $listing): \Illuminate\Testing\TestResponse
{
    return test()
        ->withHeaders(['from' => 'frontend-web'])
        ->getJson('/api/fetch-time-slots?listing_id=' . $listing->id
            . '&date=' . \Carbon\Carbon::parse('next monday')->toDateString());
}

it('reports the currency of the venue country', function () {
    $qatarId = slotCurCountry('QA', 'Qatar', 'QAR');
    DB::table('currencies')->insert([
        'country_id' => $qatarId, 'name' => 'Qatari Riyal', 'code' => 'QAR',
        'precision' => 2, 'symbol' => 'QAR', 'symbol_native' => 'ر.ق',
        'symbol_first' => 1, 'decimal_mark' => '.', 'thousands_separator' => ',',
    ]);

    $listing = slotCurListing(slotCurVenue(['country_id' => $qatarId]));

    $res = slotCurFetch($listing)->assertOk();

    expect($res->json('data.currency'))->toBe('QAR')
        ->and($res->json('data.timeSlots'))->not->toBeEmpty();
});

it('does not hand a Qatar listing another country currency', function () {
    $qatarId = slotCurCountry('QA', 'Qatar', 'QAR');
    $pkId = slotCurCountry('PK', 'Pakistan', 'PKR');
    // The platform default is a third currency, so a fallback would be visible too.
    DB::table('settings')->updateOrInsert(
        ['key' => 'platform_default_currency'],
        ['key' => 'platform_default_currency', 'value' => 'AED'],
    );

    $qatar = slotCurListing(slotCurVenue(['country_id' => $qatarId]));
    $lahore = slotCurListing(slotCurVenue(['country_id' => $pkId]));

    expect(slotCurFetch($qatar)->assertOk()->json('data.currency'))->toBe('QAR')
        ->and(slotCurFetch($lahore)->assertOk()->json('data.currency'))->toBe('PKR');
});

it('follows a venue own currency override ahead of its country', function () {
    $listing = slotCurListing(slotCurVenue(['currency_override' => 'USD']));

    expect(slotCurFetch($listing)->assertOk()->json('data.currency'))->toBe('USD');
});

it('falls back to the platform default when the venue has no country', function () {
    DB::table('settings')->updateOrInsert(
        ['key' => 'platform_default_currency'],
        ['key' => 'platform_default_currency', 'value' => 'AED'],
    );

    $listing = slotCurListing(slotCurVenue());

    expect(slotCurFetch($listing)->assertOk()->json('data.currency'))->toBe('AED');
});

it('still names the currency on a day with no slots', function () {
    $listing = slotCurListing(slotCurVenue(['currency_override' => 'USD']));

    // A day the listing is closed — the caller still has to label an empty state.
    $res = test()
        ->withHeaders(['from' => 'frontend-web'])
        ->getJson('/api/fetch-time-slots?listing_id=' . $listing->id
            . '&date=' . \Carbon\Carbon::parse('next monday')->addDay()->toDateString())
        ->assertOk();

    expect($res->json('data.currency'))->toBe('USD')
        ->and($res->json('data.timeSlots'))->toBe([]);
});
