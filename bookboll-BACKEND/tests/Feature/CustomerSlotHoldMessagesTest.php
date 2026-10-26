<?php

/**
 * What a shopper is told when their slots are gone.
 *
 * Holds last a few minutes. A customer who lingers on the checkout step comes
 * back to a selection that has lapsed, and possibly to a slot somebody else has
 * since taken. Four things were wrong at that moment:
 *
 *   The ONLINE-PAYMENT route validated straight out of the controller with no
 *   emptiness check, and `slots`/`blocks` are required_without each other — so
 *   an empty selection failed both rules and the customer mobile app showed
 *   "The slots field is required when blocks is not present. The blocks field
 *   is required when slots is not present." The cash route had checked this
 *   since forever; this one never did.
 *
 *   The storefront shows `message`, and both checkout entry points answered
 *   every other failure with the literal "Validation failed". The real reason
 *   sat in `errors.slots.0.capacity` as an internal capacity count that nothing
 *   displayed and no shopper could act on.
 *
 *   The booking path never read slot_locks at all. lockSlot() refuses a second
 *   hold on a slot another session holds, but POSTing straight to
 *   /bookings/create sailed past that and took the slot out from under whoever
 *   was mid-checkout on it.
 *
 *   A lapsed hold of one's own was indistinguishable from never having had one,
 *   so the one case where "pick it again" is exactly the right instruction was
 *   the one case nobody was given it.
 */

use App\Models\Booking;
use App\Models\LoyaltyTier;
use App\Models\SlotLock;
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
        courtPool(4, ['court' => ['max' => 1]]);

    $this->listing = $listings['court'];
    $this->venue   = $venue;
    $this->date    = $date;

    $this->customer = User::factory()->create([
        'email' => 'shopper@test.local', 'status' => User::ACTIVE, 'loyalty_tier_id' => $bronze,
    ]);
    $this->customer->assignRole('customer');

    $this->rival = User::factory()->create([
        'email' => 'rival@test.local', 'status' => User::ACTIVE, 'loyalty_tier_id' => $bronze,
    ]);
    $this->rival->assignRole('customer');
});

/** The slot this whole file argues about. */
function theSlot(): array
{
    return ['date' => test()->date, 'start_time' => '20:00', 'end_time' => '21:00', 'quantity' => 1];
}

/** Book it with cash. Pass [] for the selection the expired checkout sends. */
function bookTheSlot(?array $slots = null)
{
    return test()->actingAs(test()->customer)->postJson('/api/customer/bookings/create', [
        'venue_id'       => test()->venue->id,
        'listing_id'     => test()->listing->id,
        'slots'          => $slots ?? [theSlot()],
        'payment_method' => 'cash_on_arrival',
    ]);
}

/**
 * Book it online — the route the mobile app screenshot came from.
 *
 * Omit the argument for the real slot; pass [] for a selection that emptied,
 * and null to leave the key off entirely the way a cleared checkout does.
 */
function payForTheSlot(array|null|string $slots = 'the-slot')
{
    $payload = [
        'venue_id'       => test()->venue->id,
        'listing_id'     => test()->listing->id,
        'payment_method' => 'online_payment',
    ];

    if ($slots === 'the-slot') {
        $payload['slots'] = [theSlot()];
    } elseif (is_array($slots)) {
        $payload['slots'] = $slots;
    }

    return test()->actingAs(test()->customer)
        ->postJson('/api/customer/payments/create-checkout-session', $payload);
}

/** Someone else's booking on the slot. */
function rivalBooks(): void
{
    Booking::create([
        'user_id'    => test()->rival->id,
        'venue_id'   => test()->venue->id,
        'listing_id' => test()->listing->id,
        'date'       => test()->date,
        'start_time' => '20:00',
        'end_time'   => '21:00',
        'status'     => 'confirmed',
        'payment_status' => 'fully_paid',
        'total_price' => 10,
        'subtotal'    => 10,
    ]);
}

/** A hold on the slot. Pass a past `until` for one that has lapsed. */
function holdTheSlot(string $sessionId, ?\Carbon\Carbon $until = null): void
{
    SlotLock::create([
        'listing_id'   => test()->listing->id,
        'date'         => test()->date,
        'start_time'   => '20:00',
        'end_time'     => '21:00',
        'session_id'   => $sessionId,
        'user_id'      => null,
        'ip_address'   => '10.0.0.1',
        'locked_until' => $until ?? now()->addMinutes(5),
    ]);
}

// ── An empty selection ───────────────────────────────────────────────────────

it('asks for a time when the cash route gets an empty selection', function () {
    bookTheSlot([])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Please pick at least one time slot.');

    expect(Booking::count())->toBe(0);
});

it('asks for a time when the online route gets an empty selection', function () {
    // The mobile app screenshot: slots arrived empty and Laravel's
    // required_without pair answered for itself.
    $response = payForTheSlot([])->assertStatus(422);

    expect($response->json('message'))
        ->toBe('Please pick at least one time slot.')
        ->not->toContain('required when');
});

it('asks for a time when the online route gets no selection key at all', function () {
    $response = payForTheSlot(null)->assertStatus(422);

    expect($response->json('message'))->toBe('Please pick at least one time slot.');
});

// ── A selection that is no longer bookable ───────────────────────────────────

it('says the time is gone rather than "Validation failed"', function () {
    rivalBooks();

    $response = bookTheSlot()->assertStatus(422);

    expect($response->json('message'))
        ->not->toBe('Validation failed')
        ->toContain('no longer available')
        ->toContain('pick another slot');
});

it('tells a shopper whose own hold lapsed to pick the time again', function () {
    // The reported case: the hold ran out, and the slot went while it was down.
    holdTheSlot(getSessionId(), now()->subMinute());
    rivalBooks();

    $response = bookTheSlot()->assertStatus(422);

    expect($response->json('message'))
        ->toContain('expired')
        ->toContain('pick your time again');
});

it('gives the online payment route the same answer as cash', function () {
    rivalBooks();

    $response = payForTheSlot()->assertStatus(422);

    expect($response->json('message'))->not->toBe('Validation failed')
        ->toContain('no longer available');
});

// ── Whose hold it is ─────────────────────────────────────────────────────────

it('refuses to take a slot another shopper is holding', function () {
    holdTheSlot('a-different-browser');

    bookTheSlot()
        ->assertStatus(422)
        ->assertJsonPath('message', 'Someone else is checking out with this time right now. Please pick another slot.');

    // The point of a hold: it must still be theirs to complete.
    expect(Booking::count())->toBe(0);
});

it('lets the holder themselves complete the booking', function () {
    // A hold belongs to the session that took it — it must never block its owner.
    holdTheSlot(getSessionId());

    bookTheSlot()->assertSuccessful();

    expect(Booking::where('user_id', $this->customer->id)->count())->toBe(1);
});

it('ignores a hold that has already lapsed when the slot is still free', function () {
    // A lapsed hold releases the slot. Nobody took it, so the booking stands.
    holdTheSlot('a-different-browser', now()->subMinute());

    bookTheSlot()->assertSuccessful();

    expect(Booking::where('user_id', $this->customer->id)->count())->toBe(1);
});

it('lets staff book over a shopper hold', function () {
    // Holds guard the shopper queue, not the front desk.
    holdTheSlot('a-different-browser');

    $staff = User::factory()->create([
        'email' => 'desk@test.local', 'status' => User::ACTIVE,
        'loyalty_tier_id' => (int) LoyaltyTier::where('slug', 'bronze')->value('id'),
    ]);
    $staff->assignRole('staff');

    $this->actingAs($staff)->postJson('/api/customer/bookings/confirm', [
        'user_id'        => $this->customer->id,
        'venue_id'       => $this->venue->id,
        'listing_id'     => $this->listing->id,
        'slots'          => [theSlot()],
        'payment_method' => 'cash_on_arrival',
    ])->assertSuccessful();
});
