<?php

/**
 * GET /api/admin/bookings/{id}/crm returns the booking's currency.
 *
 * It did not. mapDashboardBooking() carried every amount — total, discount,
 * amount_paid — and no currency, because the list endpoint merged one in
 * afterwards and the single-booking responses had nothing equivalent. The only
 * currency in the CRM payload sat on the payment rows, so a booking nobody had
 * paid yet gave the admin screen no currency at all, and `location` was no
 * fallback: it exposes currency_override, which is null on any venue that
 * inherits its currency from its country.
 */

use App\Models\Booking;
use App\Models\Country;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Support\Capabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $role = Role::findOrCreate('admin', 'web');
    $caps = Capabilities::roleMap()['admin'] ?? [];
    foreach ($caps as $cap) {
        Permission::findOrCreate($cap, 'web');
    }
    $role->syncPermissions($caps);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->staff = User::factory()->create();
    $this->staff->assignRole('admin');
});

/** A country whose currency is the one its venues should charge in. */
function countryCharging(string $name, string $iso2, string $iso3, string $code): Country
{
    $country = Country::create([
        'name' => $name, 'iso2' => $iso2, 'iso3' => $iso3,
        'phone_code' => '974', 'region' => 'Asia', 'subregion' => 'Western Asia',
        'status' => 1,
    ]);

    DB::table('currencies')->insert([
        'country_id' => $country->id, 'name' => $code, 'code' => $code,
        'precision' => 2, 'symbol' => $code, 'symbol_native' => $code,
        'symbol_first' => 1, 'decimal_mark' => '.', 'thousands_separator' => ',',
    ]);

    return $country;
}

function crmVenue(array $over = []): Venue
{
    return Venue::create(array_merge([
        'name' => 'Abu Hamor', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ], $over));
}

function crmBooking(Venue $venue, User $customer): Booking
{
    $listing = Listing::create([
        'name' => 'Indoor Padel Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 175, 'capacity' => 4, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    return Booking::create([
        'user_id' => $customer->id, 'venue_id' => $venue->id, 'listing_id' => $listing->id,
        'booking_id' => 'BKN_CRM_'.uniqid(), 'date' => now()->addDays(3)->toDateString(),
        'start_time' => '19:00', 'end_time' => '21:00',
        'subtotal' => 175, 'total_price' => 175, 'status' => 'confirmed',
    ]);
}

function crm(Booking $booking)
{
    return test()->actingAs(test()->staff)
        ->withHeaders(['from' => 'admin-web'])
        ->getJson('/api/admin/bookings/'.$booking->id.'/crm');
}

it('returns the currency the venue was overridden to charge', function () {
    $booking = crmBooking(crmVenue(['currency_override' => 'QAR']), User::factory()->create());

    expect(crm($booking)->assertOk()->json('data.booking.currency'))->toBe('QAR');
});

it('falls back to the country currency when the venue has no override', function () {
    // The case `location.currency_override` could never answer: it is null here.
    $qatar   = countryCharging('Qatar', 'QA', 'QAT', 'QAR');
    $venue   = crmVenue(['country_id' => $qatar->id, 'currency_override' => null]);
    $booking = crmBooking($venue, User::factory()->create());

    $res = crm($booking)->assertOk();

    expect($res->json('data.booking.currency'))->toBe('QAR')
        ->and($res->json('data.location.currency_override'))->toBeNull();
});

it('returns a currency for a booking nobody has paid yet', function () {
    // The actual gap: payment rows were the only carrier of a currency.
    $booking = crmBooking(crmVenue(['currency_override' => 'QAR']), User::factory()->create());

    $res = crm($booking)->assertOk();

    expect($res->json('data.payments'))->toBe([])
        ->and($res->json('data.booking.currency'))->toBe('QAR');
});

it('agrees with the currency on the booking payments', function () {
    $customer = User::factory()->create();
    $booking  = crmBooking(crmVenue(['currency_override' => 'QAR']), $customer);

    Payment::create([
        'payment_id' => 'PAY_'.uniqid(), 'user_id' => $customer->id, 'booking_id' => $booking->id,
        'amount' => 175, 'currency' => 'QAR', 'method' => 'cash', 'status' => 'paid', 'type' => 'full',
    ]);

    $res = crm($booking)->assertOk();

    expect($res->json('data.booking.currency'))
        ->toBe($res->json('data.payments.0.currency'));
});

it('still returns the currency on the bookings list', function () {
    $booking = crmBooking(crmVenue(['currency_override' => 'QAR']), User::factory()->create());

    $row = collect(test()->actingAs(test()->staff)
        ->withHeaders(['from' => 'admin-web'])
        ->getJson('/api/admin/bookings/dashboard')
        ->assertOk()
        ->json('data.bookings'))
        ->firstWhere('id', (string) $booking->id);

    expect($row['currency'])->toBe('QAR');
});
