<?php

/**
 * Own-site payment methods, enforced on the SERVER.
 *
 * readmeAlamgir is explicit that the storefront's refusals are a UI guard, not
 * security: "Direct own-site cash request or legacy cash-only row -> No cash
 * booking and no automatic deposit opt-in." These call the guard the way a
 * crafted request would.
 */

use App\Models\Listing;
use App\Models\ProviderWallet;
use App\Models\User;
use App\Models\Venue;
use App\Services\BookingService;
use App\Services\BookingWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function siteListing(array $paymentOptions, ?array $wallet = null): Listing
{
    $owner = User::factory()->create();
    $venue = Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE, 'owner_user_id' => $owner->id,
        'maps_url' => 'https://maps.google.com/?q=0,0', 'country_id' => 179,
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    if ($wallet !== null) {
        ProviderWallet::create(array_merge([
            'owner_user_id' => $owner->id, 'purpose' => 'bookings',
            'setup_method' => 'bank', 'setup_status' => 'active',
            'payout_paused' => false, 'country_id' => 179, 'currency' => 'QAR',
        ], $wallet));
    }

    return Listing::create([
        'name' => 'Court A', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 100, 'capacity' => 10, 'slot_duration' => 60,
        'commission_rate' => 20, 'payment_options' => $paymentOptions,
        'max_bookings_per_slot' => 1,
    ])->fresh('venue');
}

/** Call the protected guard the way prepareCustomerCheckout does. */
function methodError(Listing $listing, string $method, string $surface = 'site'): ?array
{
    $service = app(BookingService::class);
    $fn = (new ReflectionClass($service))->getMethod('ownSiteMethodError');
    $fn->setAccessible(true);

    return $fn->invoke($service, $listing, ['surface' => $surface, 'payment_method' => $method]);
}

it('refuses cash on the own site even when a legacy row still allows it', function (string $method) {
    // The legacy shape: cash_on_arrival and site_cash_enabled both still true.
    $listing = siteListing([
        'site_deposit_enabled' => true, 'cash_on_arrival' => true, 'site_cash_enabled' => true,
    ]);

    expect(methodError($listing, $method))->not->toBeNull();
})->with(['cash_on_arrival', 'paid_cash', 'cash']);

it('takes no own-site booking at all when neither method is enabled', function () {
    $listing = siteListing(['site_cash_enabled' => true]);   // cash-only legacy row

    // Not "fall back to deposit" — no method, no booking.
    expect(methodError($listing, 'deposit_remaining_on_arrival'))->not->toBeNull()
        ->and(methodError($listing, 'online_payment'))->not->toBeNull();
});

it('allows a deposit booking with no payout connection at all', function () {
    $listing = siteListing(['site_deposit_enabled' => true]);   // no wallet created

    expect(methodError($listing, 'deposit_remaining_on_arrival'))->toBeNull();
});

it('refuses full online payment when no payout route exists', function () {
    $listing = siteListing(['site_online_enabled' => true]);

    expect(methodError($listing, 'online_payment'))->not->toBeNull();
});

it('allows full online payment once an active payout exists for the venue country', function () {
    $listing = siteListing(['site_online_enabled' => true], ['country_id' => 179]);

    expect(methodError($listing, 'online_payment'))->toBeNull();
});

it('refuses a payout route established for another country', function () {
    // Venue is in 179; the only wallet is for 167.
    $listing = siteListing(['site_online_enabled' => true], ['country_id' => 167]);

    expect(methodError($listing, 'online_payment'))->not->toBeNull();
});

it('refuses a paused or unfinished payout route', function (array $wallet) {
    $listing = siteListing(['site_online_enabled' => true], $wallet);

    expect(methodError($listing, 'online_payment'))->not->toBeNull();
})->with([
    'paused'      => [['payout_paused' => true]],
    'not active'  => [['setup_status' => 'pending']],
    'no method'   => [['setup_method' => null]],
]);

it('leaves marketplace bookings alone', function () {
    $listing = siteListing(['cash_on_arrival' => true]);

    // Cash on the marketplace is a different channel with its own rules.
    expect(methodError($listing, 'cash_on_arrival', 'marketplace'))->toBeNull();
});

it('does not let a payout connection change what Bookboll charges', function () {
    $without = siteListing(['site_deposit_enabled' => true]);
    $with    = siteListing(['site_deposit_enabled' => true], ['country_id' => 179]);

    $wallet = app(BookingWalletService::class);

    expect($wallet->venueHasActivePayout($without->venue))->toBeFalse()
        ->and($wallet->venueHasActivePayout($with->venue))->toBeTrue()
        // Same fee either way — connecting payouts never waives the commission.
        ->and(App\Support\CommissionPolicy::effectiveRatePct($without, 'site'))
        ->toBe(App\Support\CommissionPolicy::effectiveRatePct($with, 'site'));
});
