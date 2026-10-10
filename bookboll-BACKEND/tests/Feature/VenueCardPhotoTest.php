<?php

/**
 * Venue cards on /facilities showed initials for venues whose courts were fully
 * photographed.
 *
 * Providers upload photos against the court or pitch they are listing, not against
 * the address, so most venue rows carry none of their own. venueCard() read only the
 * venue's own photos and rendered the monogram when there were none — and the venue
 * view is the DEFAULT on that page, so a city of photographed courts came out as a
 * wall of initials. (Production, Doha: 11 venues, every one photo:"", while 26 of
 * their 28 listings had a photo.)
 *
 * A listing already borrows its venue's photo when it has none of its own. These pin
 * the other direction, and the one case that must NOT borrow.
 */

use App\Models\Listing;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeVenue(array $attributes = []): Venue
{
    return Venue::create(array_merge([
        'name'   => 'Test Venue',
        'status' => Venue::ACTIVE,
    ], $attributes));
}

function makeCourt(Venue $venue, array $attributes = []): Listing
{
    return Listing::create(array_merge([
        'name'                  => 'Court 1',
        'venue_id'              => $venue->id,
        'status'                => 'active',
        'base_price'            => 45,
        'capacity'              => 4,
        'slot_duration'         => 1,
        'payment_options'       => ['cash_on_arrival' => '0'],
        'max_bookings_per_slot' => 1,
    ], $attributes));
}

/** The venue cards /facilities renders, keyed by venue id. */
function venueCards(): array
{
    $res = test()->withHeader('from', 'frontend-web')
        ->getJson('/api/marketplace/venues')
        ->assertOk();

    return collect($res->json('data.venues') ?? [])->keyBy('id')->all();
}

it('shows a court photo on a venue that has none of its own', function () {
    $venue = makeVenue();
    makeCourt($venue, ['name' => 'Court A', 'photos' => ['listings/court-a.jpg']]);

    $card = venueCards()[$venue->id];

    expect($card['photo'])->toEndWith('/storage/listings/court-a.jpg');
});

it('still prefers the venue own photo when it has one', function () {
    $venue = makeVenue(['photos' => ['venues/the-club.jpg']]);
    makeCourt($venue, ['photos' => ['listings/court-a.jpg']]);

    expect(venueCards()[$venue->id]['photo'])->toEndWith('/storage/venues/the-club.jpg');
});

it('leaves the monogram alone when nothing in the venue has a photo', function () {
    $venue = makeVenue();
    makeCourt($venue);

    expect(venueCards()[$venue->id]['photo'])->toBe('');
});

it('borrows from the first court in display order, not database order', function () {
    $venue = makeVenue();
    makeCourt($venue, ['name' => 'Zulu court',  'sort_order' => 9, 'photos' => ['listings/zulu.jpg']]);
    makeCourt($venue, ['name' => 'Alpha court', 'sort_order' => 1, 'photos' => ['listings/alpha.jpg']]);

    expect(venueCards()[$venue->id]['photo'])->toEndWith('/storage/listings/alpha.jpg');
});

it('puts the borrowed photo on the venue detail page too', function () {
    $venue = makeVenue();
    makeCourt($venue, ['photos' => ['listings/court-a.jpg']]);

    $facility = test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/venues/{$venue->id}")
        ->assertOk()
        ->json('data.facility');

    expect($facility['photo'])->toEndWith('/storage/listings/court-a.jpg')
        ->and($facility['photos'])->toHaveCount(1);
});

it('never lends one court photo to another court', function () {
    // The venue may borrow from its courts, but a court with no photo of its own must
    // keep showing none — a card captioned "Court B" showing Court A is worse than a
    // blank. Only the venue is a safe stand-in for its contents.
    $venue = makeVenue();
    makeCourt($venue, ['name' => 'Court A', 'sort_order' => 1, 'photos' => ['listings/court-a.jpg']]);
    makeCourt($venue, ['name' => 'Court B', 'sort_order' => 2]);

    $listings = collect(test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/venues/{$venue->id}")
        ->json('data.facility.listings'))->keyBy('name');

    expect($listings['Court A']['photo'])->toEndWith('/storage/listings/court-a.jpg')
        ->and($listings['Court B']['photo'])->toBe('');
});
