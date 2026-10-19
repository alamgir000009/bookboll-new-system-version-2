<?php

/**
 * Spending a rewards quote (readmeAlamgir redemption contract §3).
 *
 * "Do not silently ignore rewards fields. An expired, stale, wrong-account,
 * wrong-country or already-used quote must return a clear validation failure
 * without charging." Points are reserved at payment, consumed on confirmation
 * and released on failure, so a redirect cannot leave the same points
 * spendable twice.
 */

use App\Models\LoyaltyCheckoutQuote;
use App\Models\LoyaltyPoint;
use App\Models\User;
use App\Services\LoyaltyRedemptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const CTY = 179;

function quoteRow(User $u, array $over = []): LoyaltyCheckoutQuote
{
    return LoyaltyCheckoutQuote::create(array_merge([
        'quote_id' => 'lq_'.uniqid(), 'user_id' => $u->id, 'country_id' => CTY,
        'currency' => 'QAR', 'kind' => 'listing', 'points' => 1500,
        'discount_minor' => 1500, 'order_total_minor' => 10000, 'due_now_minor' => 10000,
        'commission_minor' => 1500, 'inputs_hash' => 'hash-abc',
        'expires_at' => now()->addMinutes(10),
    ], $over));
}

function withPoints(int $n): User
{
    $u = User::factory()->create();
    LoyaltyPoint::create([
        'user_id' => $u->id, 'points' => $n, 'type' => 'credit',
        'country_id' => CTY, 'reason' => 'seed',
    ]);

    return $u;
}

it('consumes points exactly once, however many times confirmation fires', function () {
    $u = withPoints(5000);
    $q = quoteRow($u);
    $svc = app(LoyaltyRedemptionService::class);

    // A duplicate webhook is the normal case, not the exception.
    $svc->consume($q);
    $svc->consume($q);
    $svc->consume($q->fresh());

    expect(LoyaltyPoint::where('user_id', $u->id)->where('type', 'debit')->count())->toBe(1)
        ->and((int) LoyaltyPoint::where('user_id', $u->id)->where('type', 'debit')->sum('points'))->toBe(1500)
        ->and($q->fresh()->consumed_at)->not->toBeNull();
});

it('releases a reservation when payment never completes, taking no points', function () {
    $u = withPoints(5000);
    $q = quoteRow($u, ['reserved_at' => now()]);
    $svc = app(LoyaltyRedemptionService::class);

    $svc->release($q);

    expect($q->fresh()->released_at)->not->toBeNull()
        ->and(LoyaltyPoint::where('user_id', $u->id)->where('type', 'debit')->count())->toBe(0);
});

it('never releases points the customer genuinely spent', function () {
    $u = withPoints(5000);
    $q = quoteRow($u);
    $svc = app(LoyaltyRedemptionService::class);

    $svc->consume($q);
    $svc->release($q->fresh());          // a late cancellation webhook

    // Consumed stays consumed: the debit is not handed back.
    expect($q->fresh()->consumed_at)->not->toBeNull()
        ->and($q->fresh()->released_at)->toBeNull()
        ->and(LoyaltyPoint::where('user_id', $u->id)->where('type', 'debit')->count())->toBe(1);
});

it('will not consume a quote that was already released', function () {
    $u = withPoints(5000);
    $q = quoteRow($u, ['released_at' => now()]);

    app(LoyaltyRedemptionService::class)->consume($q);

    expect(LoyaltyPoint::where('user_id', $u->id)->where('type', 'debit')->count())->toBe(0);
});

it('refuses a quote belonging to another account, country, currency or order', function (array $case) {
    [$userId, $country, $currency, $hash] = $case;
    $u = withPoints(5000);
    $q = quoteRow($u);

    expect($q->usableFor($userId === 'self' ? $u->id : $u->id + 999, $country, $currency, $hash))
        ->toBeFalse();
})->with([
    'another account'  => [['other', CTY, 'QAR', 'hash-abc']],
    'another country'  => [['self', 167, 'QAR', 'hash-abc']],
    'another currency' => [['self', CTY, 'PKR', 'hash-abc']],
    'changed order'    => [['self', CTY, 'QAR', 'hash-CHANGED']],
]);

it('refuses rewards on payments that cannot honour them, rather than ignoring them', function () {
    $u = withPoints(5000);

    // A membership enrolment that silently dropped the field would charge full
    // price while the customer believed their points applied.
    $this->actingAs($u)->postJson('/api/customer/programs/some-uuid/enroll', [
        'loyalty_quote_id' => 'lq_whatever', 'loyalty_points' => 1500,
    ])->assertStatus(422)->assertJsonValidationErrors('loyalty_quote_id');
});

it('lets those payments through untouched when no rewards are claimed', function () {
    $u = withPoints(5000);

    // No rewards fields -> the guard must not interfere at all. Anything but
    // our 422 means it passed through to the real handler.
    $res = $this->actingAs($u)->postJson('/api/customer/programs/some-uuid/enroll', []);

    expect($res->status())->not->toBe(422);
});

it('accepts the rewards fields on venue checkout validation', function () {
    $rules = app(App\Services\BookingService::class)->customerBookingValidationRules();

    expect($rules)->toHaveKey('loyalty_quote_id')
        ->and($rules)->toHaveKey('loyalty_points')
        // A client-computed discount is deliberately NOT accepted.
        ->and($rules)->not->toHaveKey('loyalty_discount')
        ->and($rules)->not->toHaveKey('rewards_discount');
});

it('now advertises checkout redemption, because venue checkout enforces it', function () {
    \Nnjeim\World\Models\Country::firstOrCreate(['id' => CTY], [
        'name' => 'Qatar', 'iso2' => 'QA', 'iso3' => 'QAT',
        'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);

    $res = $this->actingAs(withPoints(100))
        ->getJson('/api/customer/loyalty?country_id='.CTY)->assertOk();

    expect($res->json('data.redemption_checkout_v1'))->toBeTrue();
});
