<?php

/**
 * The /api/customer/bookings/{confirm,create} payload contract.
 *
 * Both routes land on BookingService::createCustomerBooking(), which takes a LIST of
 * slots. The pre-rewrite flat shape (date + start_time + end_time + quantity) is
 * rejected with "Please pick at least one time slot." — the error the admin mobile
 * app hit — because normalizeCustomerSlots() reads only slots[] and blocks[].
 *
 * These tests pin the shape both mobile clients must send, and the two behaviours
 * that replaced recurring bookings: many slots in one call, and the same time on
 * several dates as nothing more than extra slots[] entries.
 */

use App\Models\Booking;
use App\Models\LoyaltyTier;
use App\Models\User;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);
    $bronze = (int) LoyaltyTier::where('slug', 'bronze')->value('id');

    Role::findOrCreate('customer', 'web');
    Role::findOrCreate('staff', 'web');

    ['listings' => $listings, 'venue' => $venue, 'date' => $date] =
        courtPool(4, ['court' => ['max' => 4]]);

    $this->listing = $listings['court'];
    $this->venue   = $venue;
    $this->date    = $date;

    $this->customer = User::factory()->create([
        'email' => 'client@test.local', 'status' => User::ACTIVE, 'loyalty_tier_id' => $bronze,
    ]);
    $this->customer->assignRole('customer');

    $this->staff = User::factory()->create([
        'email' => 'staff@test.local', 'status' => User::ACTIVE, 'loyalty_tier_id' => $bronze,
    ]);
    $this->staff->assignRole('staff');
});

it('rejects the old flat payload the admin mobile app used to send', function () {
    // Exactly the payload from the bug report, bar the ids.
    $this->actingAs($this->staff)
        ->postJson('/api/customer/bookings/confirm', [
            'user_id'        => $this->customer->id,
            'venue_id'       => $this->venue->id,
            'listing_id'     => $this->listing->id,
            'date'           => $this->date,
            'start_time'     => '20:00',
            'end_time'       => '21:00',
            'quantity'       => 1,
            'add_ons'        => [],
            'payment_method' => 'cash_on_arrival',
            'price'          => 100,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Please pick at least one time slot.');

    expect(Booking::count())->toBe(0);
});

it('accepts the slots[] payload the app now sends', function () {
    $this->actingAs($this->staff)
        ->postJson('/api/customer/bookings/confirm', [
            'user_id'        => $this->customer->id,
            'venue_id'       => $this->venue->id,
            'listing_id'     => $this->listing->id,
            'slots'          => [
                ['date' => $this->date, 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1],
            ],
            'payment_method' => 'cash_on_arrival',
        ])
        ->assertSuccessful();

    expect(Booking::count())->toBeGreaterThan(0);
});

it('books several slots on one date in a single call', function () {
    $this->actingAs($this->staff)
        ->postJson('/api/customer/bookings/confirm', [
            'user_id'        => $this->customer->id,
            'venue_id'       => $this->venue->id,
            'listing_id'     => $this->listing->id,
            'slots'          => [
                ['date' => $this->date, 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1],
                ['date' => $this->date, 'start_time' => '21:00', 'end_time' => '22:00', 'quantity' => 1],
            ],
            'payment_method' => 'cash_on_arrival',
        ])
        ->assertSuccessful();

    expect(Booking::where('user_id', $this->customer->id)->pluck('start_time')
        ->map(fn ($t) => substr((string) $t, 0, 5))->unique()->sort()->values()->all())
        ->toContain('20:00', '21:00');
});

it('treats a repeated time on another date as just more slots, not a recurrence', function () {
    $second = \Carbon\Carbon::parse($this->date)->addWeek()->toDateString();

    // The listing is only open on the weekday courtPool built, so a week later is
    // the same weekday -- the schedule applies and no recurring_bookings key exists.
    $this->actingAs($this->staff)
        ->postJson('/api/customer/bookings/confirm', [
            'user_id'        => $this->customer->id,
            'venue_id'       => $this->venue->id,
            'listing_id'     => $this->listing->id,
            'slots'          => [
                ['date' => $this->date, 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1],
                ['date' => $second,     'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1],
            ],
            'payment_method' => 'cash_on_arrival',
        ])
        ->assertSuccessful();

    expect(Booking::where('user_id', $this->customer->id)->pluck('date')
        ->map(fn ($d) => substr((string) $d, 0, 10))->unique()->sort()->values()->all())
        ->toEqualCanonicalizing([$this->date, $second]);
});

it('books on behalf of the customer in user_id, not the signed-in staff member', function () {
    $this->actingAs($this->staff)
        ->postJson('/api/customer/bookings/confirm', [
            'user_id'        => $this->customer->id,
            'venue_id'       => $this->venue->id,
            'listing_id'     => $this->listing->id,
            'slots'          => [
                ['date' => $this->date, 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1],
            ],
            'payment_method' => 'cash_on_arrival',
        ])
        ->assertSuccessful();

    expect(Booking::where('user_id', $this->customer->id)->exists())->toBeTrue();
    expect(Booking::where('user_id', $this->staff->id)->exists())->toBeFalse();
});

it('still rejects an empty slots array', function () {
    $this->actingAs($this->staff)
        ->postJson('/api/customer/bookings/confirm', [
            'user_id'        => $this->customer->id,
            'venue_id'       => $this->venue->id,
            'listing_id'     => $this->listing->id,
            'slots'          => [],
            'payment_method' => 'cash_on_arrival',
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Please pick at least one time slot.');
});

it('rejects slots[] at the pre-flight validate endpoint — which is why the app skips it', function () {
    // /bookings/validate still validates the FLAT shape, so sending the new payload
    // through it would fail even though the booking itself is valid.
    $this->actingAs($this->staff)
        ->postJson('/api/customer/bookings/validate', [
            'venue_id'   => $this->venue->id,
            'listing_id' => $this->listing->id,
            'slots'      => [
                ['date' => $this->date, 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1],
            ],
        ])
        ->assertStatus(422);
});

/* ─────────── editing: PUT /customer/bookings/{id}/update ─────────── */

it('moves an existing booking to a new time instead of creating another', function () {
    $this->actingAs($this->staff)
        ->postJson('/api/customer/bookings/confirm', [
            'user_id'        => $this->customer->id,
            'venue_id'       => $this->venue->id,
            'listing_id'     => $this->listing->id,
            'slots'          => [
                ['date' => $this->date, 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1],
            ],
            'payment_method' => 'cash_on_arrival',
        ])->assertSuccessful();

    $booking     = Booking::where('user_id', $this->customer->id)->sole();
    $countBefore = Booking::count();

    // Exactly what the edit screen sends: flat, one slot, no slots[] and no quantity.
    $this->actingAs($this->staff)
        ->putJson("/api/customer/bookings/{$booking->id}/update", [
            'user_id'        => $this->customer->id,
            'venue_id'       => $this->venue->id,
            'listing_id'     => $this->listing->id,
            'date'           => $this->date,
            'start_time'     => '21:00',
            'end_time'       => '22:00',
            'payment_method' => 'cash_on_arrival',
        ])
        ->assertSuccessful();

    expect(Booking::count())->toBe($countBefore);

    $booking->refresh();
    expect(substr((string) $booking->start_time, 0, 5))->toBe('21:00');
    expect(substr((string) $booking->end_time, 0, 5))->toBe('22:00');
});

it('moves an existing booking to another date in place', function () {
    $this->actingAs($this->staff)
        ->postJson('/api/customer/bookings/confirm', [
            'user_id'        => $this->customer->id,
            'venue_id'       => $this->venue->id,
            'listing_id'     => $this->listing->id,
            'slots'          => [
                ['date' => $this->date, 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1],
            ],
            'payment_method' => 'cash_on_arrival',
        ])->assertSuccessful();

    $booking     = Booking::where('user_id', $this->customer->id)->sole();
    $countBefore = Booking::count();
    $nextWeek    = \Carbon\Carbon::parse($this->date)->addWeek()->toDateString();

    $this->actingAs($this->staff)
        ->putJson("/api/customer/bookings/{$booking->id}/update", [
            'user_id'    => $this->customer->id,
            'venue_id'   => $this->venue->id,
            'listing_id' => $this->listing->id,
            'date'       => $nextWeek,
            'start_time' => '20:00',
            'end_time'   => '21:00',
        ])
        ->assertSuccessful();

    expect(Booking::count())->toBe($countBefore);
    expect(substr((string) $booking->refresh()->date, 0, 10))->toBe($nextWeek);
});
