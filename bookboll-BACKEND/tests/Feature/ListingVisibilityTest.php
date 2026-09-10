<?php

/**
 * A listing is no longer simply active or inactive: it carries one flag per
 * surface (show_on_admin, show_on_customer). Both off means inactive
 * everywhere, and `status` is derived from the pair so that everything still
 * reading `status` keeps working.
 *
 * Staff surfaces are identified by the `from` request header: 'admin-web' and
 * 'admin_mobile_app'. Everything else counts as a customer.
 */

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Hide a listing from customers, leaving it bookable by staff. */
function makeAdminOnly(Listing $listing): Listing
{
    $listing->update(['show_on_admin' => true, 'show_on_customer' => false]);
    return $listing->fresh();
}

/** Hide a listing from staff, leaving it bookable by customers. */
function makeCustomerOnly(Listing $listing): Listing
{
    $listing->update(['show_on_admin' => false, 'show_on_customer' => true]);
    return $listing->fresh();
}

/**
 * Ids from the shared /api/fetch-listings feed as seen by one surface. The
 * customer site browses with it and the admin booking screen picks from it, so
 * the `from` header decides what comes back.
 */
function listingIdsSeenBy(?string $from = null): array
{
    $request = $from ? test()->withHeaders(['from' => $from]) : test();

    return collect($request->getJson('/api/fetch-listings')->json('data.listings.data') ?? [])
        ->pluck('id')->all();
}

/** Ids returned to the customer website. */
function customerListingIds(): array
{
    return listingIdsSeenBy('frontend-web');
}

describe('status is derived from the two visibility flags', function () {

    it('keeps a listing active while either surface can see it', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        $listing           = $l['badminton'];

        expect($listing->status)->toBe('active')
            ->and($listing->visibility)->toBe('both');

        expect(makeAdminOnly($listing))
            ->status->toBe('active')
            ->visibility->toBe('admin_only');

        expect(makeCustomerOnly($listing))
            ->status->toBe('active')
            ->visibility->toBe('customer_only');
    });

    it('marks a listing inactive once both surfaces are off', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);

        $l['badminton']->update(['show_on_admin' => false, 'show_on_customer' => false]);

        expect($l['badminton']->fresh())
            ->status->toBe('inactive')
            ->visibility->toBe('inactive');
    });

    it('does not resurrect a hidden listing on an unrelated save', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        $listing           = $l['badminton'];

        $listing->update(['show_on_admin' => false, 'show_on_customer' => false]);
        $listing->update(['sort_order' => 5]);

        expect($listing->fresh()->status)->toBe('inactive');
    });
});

describe('the customer listings feed respects show_on_customer', function () {

    it('lists a listing visible to both', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);

        expect(customerListingIds())->toContain($l['badminton']->id);
    });

    it('hides an admin-only listing', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeAdminOnly($l['badminton']);

        expect(customerListingIds())->not->toContain($l['badminton']->id);
    });

    it('still lists a customer-only listing', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeCustomerOnly($l['badminton']);

        expect(customerListingIds())->toContain($l['badminton']->id);
    });

    it('hides a listing that is off on both surfaces', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        $l['badminton']->update(['show_on_admin' => false, 'show_on_customer' => false]);

        expect(customerListingIds())->not->toContain($l['badminton']->id);
    });

    it('treats a caller with no from header as a customer', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeAdminOnly($l['badminton']);

        expect(listingIdsSeenBy())->not->toContain($l['badminton']->id);
    });
});

describe('the same feed serves the admin booking screen', function () {

    // BookingCreate.vue picks its listing from /api/fetch-listings, so an
    // admin-only listing has to come back for admin-web and a customer-only
    // one has to be withheld.
    it('shows an admin-only listing to the admin panel', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeAdminOnly($l['badminton']);

        expect(listingIdsSeenBy('admin-web'))->toContain($l['badminton']->id);
    });

    it('hides a customer-only listing from the admin panel', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeCustomerOnly($l['badminton']);

        expect(listingIdsSeenBy('admin-web'))->not->toContain($l['badminton']->id);
    });

    it('hides a fully inactive listing from the admin panel', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        $l['badminton']->update(['show_on_admin' => false, 'show_on_customer' => false]);

        expect(listingIdsSeenBy('admin-web'))->not->toContain($l['badminton']->id);
    });
});

describe('the admin mobile listings feed respects show_on_admin', function () {

    beforeEach(function () {
        Role::findOrCreate('admin', 'web');
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
    });

    /** Ids returned by GET /api/listings, the admin mobile app's feed. */
    $adminIds = function ($test) {
        return collect(
            $test->actingAs($test->staff)
                ->withHeaders(['from' => MobileApps::ADMIN])
                ->getJson('/api/listings')
                ->json('data.data') ?? []
        )->pluck('id')->all();
    };

    it('lists a listing visible to both', function () use ($adminIds) {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);

        expect($adminIds($this))->toContain($l['badminton']->id);
    });

    it('hides a customer-only listing', function () use ($adminIds) {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeCustomerOnly($l['badminton']);

        expect($adminIds($this))->not->toContain($l['badminton']->id);
    });

    it('still lists an admin-only listing', function () use ($adminIds) {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeAdminOnly($l['badminton']);

        expect($adminIds($this))->toContain($l['badminton']->id);
    });
});

describe('the booking surface gates slots, locks and detail', function () {

    it('withholds slots from the surface that may not see the listing', function () {
        ['listings' => $l, 'date' => $date] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeAdminOnly($l['badminton']);

        $customer = test()->withHeaders(['from' => 'frontend-web'])
            ->getJson('/api/fetch-time-slots?listing_id=' . $l['badminton']->id . '&date=' . $date);
        expect($customer->json('data.timeSlots') ?? [])->toBeEmpty();

        $admin = test()->withHeaders(['from' => 'admin-web'])
            ->getJson('/api/fetch-time-slots?listing_id=' . $l['badminton']->id . '&date=' . $date);
        expect($admin->json('data.timeSlots') ?? [])->not->toBeEmpty();
    });

    it('refuses a lock from the surface that may not see the listing', function () {
        ['listings' => $l, 'date' => $date] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeCustomerOnly($l['badminton']);

        test()->withHeaders(['from' => 'admin-web', 'User-Agent' => 'staff'])
            ->postJson('/api/lock-slot', [
                'listing_id' => $l['badminton']->id,
                'date'       => $date,
                'start_time' => '20:00',
                'end_time'   => '21:00',
            ])->assertJson(['success' => false]);

        test()->withHeaders(['from' => 'frontend-web', 'User-Agent' => 'shopper'])
            ->postJson('/api/lock-slot', [
                'listing_id' => $l['badminton']->id,
                'date'       => $date,
                'start_time' => '20:00',
                'end_time'   => '21:00',
            ])->assertJson(['success' => true]);
    });

    it('reports a hidden listing as unavailable to check-slot-availability', function () {
        ['listings' => $l, 'date' => $date] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeAdminOnly($l['badminton']);

        $response = test()->withHeaders(['from' => 'frontend-web'])
            ->postJson('/api/check-slot-availability', [
                'listing_id' => $l['badminton']->id,
                'date'       => $date,
                'start_time' => '20:00',
                'end_time'   => '21:00',
            ]);

        expect($response->json('data.available'))->toBeFalse();
    });

    it('404s the detail endpoint for the surface that may not see the listing', function () {
        ['listings' => $l] = courtPool(6, ['badminton' => ['max' => 6]]);
        makeAdminOnly($l['badminton']);

        test()->withHeaders(['from' => 'frontend-web'])
            ->getJson('/api/fetch-listing/' . $l['badminton']->id)
            ->assertNotFound();

        test()->withHeaders(['from' => 'admin-web'])
            ->getJson('/api/fetch-listing/' . $l['badminton']->id)
            ->assertOk();
    });
});

describe('the admin listing form writes both flags', function () {

    beforeEach(function () {
        Role::findOrCreate('admin', 'web');
        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');

        $type  = \App\Models\SportsType::create([
            'title'     => 'Racket',
            'sub_title' => 'Racket sports',
            'slug'      => 'racket',
        ]);
        $this->category = \App\Models\SportsCategory::create([
            'name'           => 'Badminton',
            'slug'           => 'badminton',
            'sports_type_id' => $type->id,
        ]);
        $this->venue = \App\Models\Venue::create(['name' => 'Form Venue', 'status' => 'active']);
    });

    /**
     * The payload ListingForm.vue submits. Passing null for both flags mimics a
     * client that predates them and sends no visibility at all.
     */
    $payload = function ($test, ?bool $admin, ?bool $customer) {
        $schedule = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->map(fn($day) => [
                'day'        => $day,
                'is_open'    => true,
                'start_time' => '09:00',
                'end_time'   => '22:00',
            ])->all();

        $body = [
            'name'                    => 'Court A',
            'venue_id'                => $test->venue->id,
            'sports_category_id'      => $test->category->id,
            'description'             => 'A court',
            'base_price'              => 50,
            'max_bookings_per_slot'   => 1,
            'capacity'                => 4,
            'slot_duration'           => 1,
            'online_payment'          => 0,
            'allow_multiple_bookings' => 0,
            'payment_options'         => ['cash_on_arrival' => 1],
            'amenities'               => [],
            'schedule'                => $schedule,
        ];

        if ($admin !== null) {
            $body['show_on_admin'] = $admin ? 1 : 0;
        }
        if ($customer !== null) {
            $body['show_on_customer'] = $customer ? 1 : 0;
        }

        return $body;
    };

    $submit = function ($test, ?bool $admin, ?bool $customer) use ($payload) {
        return $test->actingAs($test->staff)
            ->withHeaders(['from' => 'admin-web'])
            ->postJson('/api/admin/listings', $payload($test, $admin, $customer));
    };

    it('stores an admin-only listing as active', function () use ($submit) {
        $submit($this, admin: true, customer: false)->assertOk();

        expect(Listing::latest('id')->first())
            ->show_on_admin->toBeTrue()
            ->show_on_customer->toBeFalse()
            ->status->toBe('active')
            ->visibility->toBe('admin_only');
    });

    it('stores a listing with both boxes cleared as inactive', function () use ($submit) {
        $submit($this, admin: false, customer: false)->assertOk();

        expect(Listing::latest('id')->first())
            ->status->toBe('inactive')
            ->visibility->toBe('inactive');
    });

    it('rejects a payload that omits visibility on create', function () use ($submit) {
        $this->actingAs($this->staff)
            ->withHeaders(['from' => 'admin-web'])
            ->postJson('/api/admin/listings', ['name' => 'No visibility'])
            ->assertJsonValidationErrors(['show_on_admin', 'show_on_customer']);
    });

    it('leaves visibility untouched when an update omits the flags', function () use ($submit, $payload) {
        $submit($this, admin: true, customer: false)->assertOk();
        $listing = Listing::latest('id')->first();

        $body         = $payload($this, null, null);
        $body['name'] = 'Renamed';

        $this->actingAs($this->staff)
            ->withHeaders(['from' => 'admin-web'])
            ->putJson('/api/admin/listings/' . $listing->id, $body)
            ->assertOk();

        expect($listing->fresh())
            ->name->toBe('Renamed')
            ->show_on_admin->toBeTrue()
            ->show_on_customer->toBeFalse()
            ->status->toBe('active');
    });
});
