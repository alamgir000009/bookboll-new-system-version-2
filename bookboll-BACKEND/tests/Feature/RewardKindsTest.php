<?php

/**
 * Rewards on memberships and hosted events.
 *
 * The quote endpoint priced venue bookings and refused everything else, so
 * those three checkouts could not offer a reward at all. They are priced now —
 * each through the service that actually CHARGES it, never through a copy of
 * its arithmetic, because a reward quote that disagrees with the charge either
 * shorts the customer or gives away money nobody earned.
 *
 * The funding rule does not change: a reward comes out of Bookboll's own share
 * and nothing else. On a hosted event that share is the seats service fee plus
 * the court's commission — not the court price Bookboll collects on the
 * venue's behalf.
 */

use App\Models\Booking;
use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyProgram;
use App\Models\Payment;
use App\Models\ProviderWallet;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const RK_QA = 179;

beforeEach(function () {
    \Nnjeim\World\Models\Country::firstOrCreate(['id' => RK_QA], [
        'name' => 'Qatar', 'iso2' => 'QA', 'iso3' => 'QAT',
        'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);
    LoyaltyProgram::create([
        'country_id' => RK_QA, 'name' => 'Rewards', 'earn_rate' => 1, 'redeem_rate' => 100,
        'redeem_value' => 1, 'min_redeem' => 100, 'max_redeem_pct' => 100,
        'expiry_months' => 12, 'is_active' => true,
    ]);
});

function rkCustomer(int $points = 100000): User
{
    $u = User::factory()->create(['country_id' => RK_QA, 'uuid' => (string) Str::uuid()]);
    LoyaltyPoint::create([
        'user_id' => $u->id, 'points' => $points, 'type' => 'credit',
        'country_id' => RK_QA, 'reason' => 'test',
    ]);

    return $u->fresh();
}

function rkVenue(?User $owner = null): Venue
{
    return Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE,
        'owner_user_id' => ($owner ?: User::factory()->create())->id,
        'maps_url' => 'https://maps.google.com/?q=0,0', 'country_id' => RK_QA,
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

function rkListing(Venue $venue, float $price = 100, float $commission = 20, array $options = []): Listing
{
    return Listing::create([
        'name' => 'Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => $price, 'capacity' => 10, 'slot_duration' => 60,
        'commission_rate' => $commission, 'max_bookings_per_slot' => 1,
        'payment_options' => $options,
    ])->fresh('venue');
}

/**
 * A programme that charges on sign-up.
 *
 * `free_trial_days` defaults to 14 in the schema, so a programme created
 * without saying otherwise takes no payment today — which is exactly why the
 * reward basis has to read it rather than assume a charge.
 */
function rkProgram(array $over = []): ClassProgram
{
    return ClassProgram::create(array_merge([
        'uuid' => (string) Str::uuid(), 'name' => 'Padel Academy',
        'monthly_price' => 200, 'currency' => 'QAR', 'billing_interval' => 'monthly',
        'commission_percentage' => 15, 'status' => 'active',
        'free_trial_days' => 0, 'free_trial_sessions' => 0,
    ], $over));
}

function rkQuote(User $user, string $kind, array $checkout, array $over = [])
{
    return test()->actingAs($user)->postJson('/api/customer/loyalty/checkout-quote', array_merge([
        'kind' => $kind, 'country_id' => RK_QA, 'currency' => 'QAR', 'checkout' => $checkout,
    ], $over));
}

// ── Memberships ─────────────────────────────────────────────────────────────

it('prices a reward on a membership out of the programme commission', function () {
    $program = rkProgram(['monthly_price' => 200, 'commission_percentage' => 15]);

    $data = rkQuote(rkCustomer(), 'membership', ['program_id' => $program->id])->assertOk()->json('data');

    // QAR 200 at 15% — Bookboll's fee is 30, and that is the whole ceiling.
    expect($data['kind'])->toBe('membership');
    expect($data['bookboll_share']['commission'])->toBe(30);
    expect($data['discount'])->toBe(30);
    expect($data['order_total_before'])->toBe(200);
    expect($data['quote_id'])->toStartWith('lq_');
});

it('counts the joining fee into the membership charge it is taken from', function () {
    $program = rkProgram(['monthly_price' => 200, 'joining_fee' => 100, 'commission_percentage' => 15]);

    $data = rkQuote(rkCustomer(), 'membership', ['program_id' => $program->id])->assertOk()->json('data');

    // The first charge is 300, so the fee is 45 — the same base the charge uses.
    expect($data['order_total_before'])->toBe(300);
    expect($data['bookboll_share']['commission'])->toBe(45);
});

it('offers nothing on a membership that collects nothing today', function () {
    // A free trial takes no payment now, so there is no charge for points to
    // reduce. A rate applied to an amount nobody paid would invent revenue.
    $program = rkProgram(['free_trial_days' => 14]);

    $data = rkQuote(rkCustomer(), 'membership', ['program_id' => $program->id])->assertOk()->json('data');

    expect($data['points'])->toBe(0);
    expect($data['quote_id'])->toBeNull();
    // No charge today means no fee today, which is the first thing the funding
    // rule checks. Either way it declines rather than discounting a charge
    // nobody has made.
    expect($data['reason'])->toContain('no Bookboll fee');
});

it('prices a discounted membership on what is actually charged', function () {
    $program = rkProgram([
        'monthly_price' => 1200, 'billing_interval' => 'yearly',
        'discount_months' => 3, 'commission_percentage' => 10,
    ]);

    $data = rkQuote(rkCustomer(), 'membership', ['program_id' => $program->id])->assertOk()->json('data');

    // Three months off twelve: 900 charged, so the fee is 90.
    expect($data['order_total_before'])->toBe(900);
    expect($data['bookboll_share']['commission'])->toBe(90);
});

it('refuses a membership in another currency rather than converting', function () {
    $program = rkProgram(['currency' => 'AED']);

    rkQuote(rkCustomer(), 'membership', ['program_id' => $program->id])->assertStatus(422);
});

it('does not let a quote for one membership be spent on another', function () {
    // Two identically priced programmes in one country would fingerprint the
    // same without the subject, and a quote for one could pay for the other.
    $a = rkProgram(['name' => 'Padel A']);
    $b = rkProgram(['name' => 'Padel B']);
    $customer = rkCustomer();

    $first = rkQuote($customer, 'membership', ['program_id' => $a->id])->assertOk()->json('data.quote_id');
    $second = rkQuote($customer, 'membership', ['program_id' => $b->id])->assertOk()->json('data.quote_id');

    $hashes = \App\Models\LoyaltyCheckoutQuote::whereIn('quote_id', [$first, $second])->pluck('inputs_hash');
    expect($hashes[0])->not->toBe($hashes[1]);
});

// ── Hosted events ───────────────────────────────────────────────────────────

/** The payload the hosting wizard sends. */
function rkHostCheckout(?Listing $listing, array $over = []): array
{
    return array_merge([
        'listing_id'       => $listing?->id,
        'game_date'        => now()->addDays(3)->format('Y-m-d'),
        'start_time'       => '10:00',
        'end_time'         => '11:00',
        'max_players'      => 10,
        'price_per_person' => 20,
    ], $over);
}

it('prices a reward on a hosted event out of the seats fee and the court commission', function () {
    $listing = rkListing(rkVenue(), 100, 20, ['deposit' => ['enabled' => true, 'percentage' => 20]]);

    $data = rkQuote(rkCustomer(), 'host_game', rkHostCheckout($listing))->assertOk()->json('data');

    expect($data['kind'])->toBe('host_game');
    // Seats fee on 10 x 20 and the court deposit, which IS the commission.
    expect($data['bookboll_share']['event_fee'])->toBeGreaterThan(0);
    expect($data['bookboll_share']['commission'])->toBeGreaterThan(0);
    expect($data['discount'])->toBe(
        $data['bookboll_share']['event_fee'] + $data['bookboll_share']['commission']
    );
});

it('never funds a hosted-event reward from the court money owed to the venue', function () {
    // Full online: Bookboll collects the whole court and credits the venue its
    // share. Only the commission is Bookboll's, so that is the ceiling.
    $listing = rkListing(rkVenue(), 500, 20, ['online_payment_enabled' => true]);

    $data = rkQuote(rkCustomer(), 'host_game', rkHostCheckout($listing, ['payment_method' => 'online']))
        ->assertOk()->json('data');

    expect((float) $data['bookboll_share']['commission'])->toBe(100.0); // 20% of 500
    // Emphatically NOT the 500 collected: 400 of that is the venue's.
    expect($data['discount'])->toBeLessThanOrEqual(
        $data['bookboll_share']['commission'] + $data['bookboll_share']['event_fee']
    );
    expect($data['discount'])->toBeLessThan(500);
});

it('prices a tournament the same way a game is priced', function () {
    $listing = rkListing(rkVenue(), 100, 20, ['deposit' => ['enabled' => true, 'percentage' => 20]]);
    $customer = rkCustomer();

    $game = rkQuote($customer, 'host_game', rkHostCheckout($listing))->assertOk()->json('data');
    $tournament = rkQuote($customer, 'host_tournament', rkHostCheckout($listing))->assertOk()->json('data');

    expect($tournament['discount'])->toBe($game['discount']);
    // Different kinds all the same: a quote for one must not pay for the other.
    $hashes = \App\Models\LoyaltyCheckoutQuote::whereIn('quote_id', [$game['quote_id'], $tournament['quote_id']])
        ->pluck('inputs_hash');
    expect($hashes[0])->not->toBe($hashes[1]);
});

it('prices an own-spot event, where every figure is Bookboll money', function () {
    // No court, so the seats fee is the whole of Bookboll's share.
    $data = rkQuote(rkCustomer(), 'host_game', rkHostCheckout(null, ['listing_id' => null]))
        ->assertOk()->json('data');

    expect($data['bookboll_share']['commission'])->toBe(0);
    expect($data['bookboll_share']['event_fee'])->toBeGreaterThan(0);
});

it('offers nothing on a hosted event that collects nothing online', function () {
    $listing = rkListing(rkVenue(), 100, 20, ['cash_on_arrival' => true]);

    // Cash on arrival with free seats: nothing is taken now.
    $data = rkQuote(rkCustomer(), 'host_game', rkHostCheckout($listing, [
        'payment_method' => 'cash', 'price_per_person' => 0,
    ]))->assertOk()->json('data');

    expect($data['points'])->toBe(0);
});

it('refuses a hosted event on a court that does not exist', function () {
    rkQuote(rkCustomer(), 'host_game', rkHostCheckout(null, ['listing_id' => 999999]))
        ->assertStatus(422);
});

// ── The venue is paid the same, with or without a reward ────────────────────

it('pays the venue on the court price, not on what was left after a reward', function () {
    // This was wrong: the wallet credit was computed from the amount collected
    // AFTER a reward, so redeeming points on a hosted game quietly cut the
    // venue's payout by the non-commission part of the discount.
    $owner = User::factory()->create(['country_id' => RK_QA]);
    $venue = rkVenue($owner);
    $listing = rkListing($venue, 500, 20, ['online_payment_enabled' => true]);
    $customer = rkCustomer();

    ProviderWallet::create([
        'owner_user_id' => $owner->id, 'setup_method' => 'stripe', 'setup_status' => 'active',
        'payout_paused' => false, 'country_id' => RK_QA, 'purpose' => 'bookings', 'currency' => 'QAR',
    ]);

    $booking = Booking::create([
        'user_id' => $customer->id, 'venue_id' => $venue->id, 'listing_id' => $listing->id,
        'date' => now()->addDays(3)->format('Y-m-d'), 'start_time' => '10:00', 'end_time' => '11:00',
        'status' => BookingStatus::CONFIRMED, 'subtotal' => 500, 'total_price' => 500,
    ]);

    // The court row as recordHostPayments writes it: collected net of a QAR 100
    // reward, which is exactly Bookboll's 20% fee on this court.
    Payment::create([
        'payment_id' => 'PAY_'.uniqid(), 'user_id' => $customer->id, 'booking_id' => $booking->id,
        'type' => 'full', 'method' => 'online', 'status' => PaymentStatus::PENDING,
        'amount' => 400, 'currency' => 'QAR', 'recipient' => '@bookboll',
    ]);

    app(\App\Services\CustomerGameService::class);
    $service = new \ReflectionMethod(\App\Services\CustomerGameService::class, 'settleHostBooking');
    $service->setAccessible(true);
    $service->invoke(app(\App\Services\CustomerGameService::class), $booking->fresh(), 'ch_test', 'pi_test');

    // The venue's share is 80% of the court's real price — 400 — whatever the
    // reward took off Bookboll's fee.
    $credit = Payment::where('booking_id', $booking->id)
        ->where('type', 'booking_earning')
        ->first();

    expect($credit)->not->toBeNull();
    expect((float) $credit->amount)->toBe(400.0);
});

it('leaves the venue payout identical whether a reward was used or not', function () {
    $payout = function (float $collected, float $courtPrice) {
        $owner = User::factory()->create(['country_id' => RK_QA]);
        $venue = rkVenue($owner);
        $listing = rkListing($venue, $courtPrice, 20, ['online_payment_enabled' => true]);
        $customer = rkCustomer();

        ProviderWallet::create([
            'owner_user_id' => $owner->id, 'setup_method' => 'stripe', 'setup_status' => 'active',
            'payout_paused' => false, 'country_id' => RK_QA, 'purpose' => 'bookings', 'currency' => 'QAR',
        ]);

        $booking = Booking::create([
            'user_id' => $customer->id, 'venue_id' => $venue->id, 'listing_id' => $listing->id,
            'date' => now()->addDays(3)->format('Y-m-d'), 'start_time' => '10:00', 'end_time' => '11:00',
            'status' => BookingStatus::CONFIRMED, 'subtotal' => $courtPrice, 'total_price' => $courtPrice,
        ]);

        Payment::create([
            'payment_id' => 'PAY_'.uniqid(), 'user_id' => $customer->id, 'booking_id' => $booking->id,
            'type' => 'full', 'method' => 'online', 'status' => PaymentStatus::PENDING,
            'amount' => $collected, 'currency' => 'QAR', 'recipient' => '@bookboll',
        ]);

        $m = new \ReflectionMethod(\App\Services\CustomerGameService::class, 'settleHostBooking');
        $m->setAccessible(true);
        $m->invoke(app(\App\Services\CustomerGameService::class), $booking->fresh(), 'ch_x', 'pi_x');

        return (float) Payment::where('booking_id', $booking->id)->where('type', 'booking_earning')->value('amount');
    };

    // No reward, and a full QAR 100 reward out of a QAR 100 fee.
    expect($payout(500, 500))->toBe($payout(400, 500));
});

it('declines rather than over-offering when the two trial rules disagree', function () {
    // CustomerProgramService::subscribe() reads free_trial_days and ignores
    // free_trial_enabled; MembershipBillingService::trialDays() honours it. A
    // programme with the flag off and days left over is read by one path as a
    // trial and by the other as a charge. The reward basis takes the cautious
    // reading: declining costs nothing, whereas discounting a charge that is
    // never taken gives money away.
    $program = rkProgram(['free_trial_enabled' => false, 'free_trial_days' => 14]);

    $data = rkQuote(rkCustomer(), 'membership', ['program_id' => $program->id])->assertOk()->json('data');

    expect($data['points'])->toBe(0);
    expect($data['quote_id'])->toBeNull();
});

it('fingerprints a venue booking exactly as it did before the other kinds existed', function () {
    // The fingerprint gained an event fee, a booking fee and a subject. All
    // three default to the values a venue booking already had, so a quote
    // issued before this deploy still matches when it is spent after it.
    $listing = rkListing(rkVenue(), 100, 20);

    $policy = \App\Support\CommissionPolicy::quote(
        $listing, 'marketplace', 10000, 'QAR', 'deposit'
    );

    $withDefaults = \App\Services\LoyaltyCheckoutQuoteService::inputsHash($listing, $policy, RK_QA, 'QAR');
    $spelledOut = \App\Services\LoyaltyCheckoutQuoteService::inputsHash(
        $listing,
        $policy + ['event_fee_minor' => 0, 'booking_fee_minor' => 0],
        RK_QA,
        'QAR',
        null
    );

    expect($withDefaults)->toBe($spelledOut);

    // And a non-zero share genuinely changes it, so the new fields are read.
    $changed = \App\Services\LoyaltyCheckoutQuoteService::inputsHash(
        $listing, $policy + ['event_fee_minor' => 500], RK_QA, 'QAR'
    );
    expect($changed)->not->toBe($withDefaults);
});

it('derives a membership promocode discount from the code, never from the browser', function () {
    $program = rkProgram(['monthly_price' => 200, 'commission_percentage' => 15]);
    $customer = rkCustomer();

    \App\Models\Promocode::create([
        'code' => 'HALFOFF', 'description' => 'Half off', 'discount_type' => 'percentage',
        'discount_value' => 50, 'status' => 'active',
        'valid_from' => now()->subDay(), 'valid_to' => now()->addMonth(),
    ]);

    // 200 less 50% is 100 charged, so the fee is 15 — not the 30 the full
    // price would carry.
    $data = rkQuote($customer, 'membership', ['program_id' => $program->id, 'promocode' => 'HALFOFF'])
        ->assertOk()->json('data');

    expect($data['order_total_before'])->toBe(100);
    expect($data['bookboll_share']['commission'])->toBe(15);

    // A discount the browser claims is ignored: understating it would inflate
    // the fee and hand out a bigger reward than Bookboll earned.
    $lying = rkQuote($customer, 'membership', [
        'program_id' => $program->id, 'promocode' => 'HALFOFF', 'discount' => 0,
    ])->assertOk()->json('data');

    expect($lying['order_total_before'])->toBe(100);
    expect($lying['bookboll_share']['commission'])->toBe(15);
});

it('ignores a promocode that could not be used', function () {
    $program = rkProgram(['monthly_price' => 200, 'commission_percentage' => 15]);

    $data = rkQuote(rkCustomer(), 'membership', ['program_id' => $program->id, 'promocode' => 'NOPE'])
        ->assertOk()->json('data');

    // Full price, which is also what the charge would take with that code.
    expect($data['order_total_before'])->toBe(200);
    expect($data['bookboll_share']['commission'])->toBe(30);
});
