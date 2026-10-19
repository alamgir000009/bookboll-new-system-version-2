<?php

/**
 * Manual membership subscriber payments (readmeAlamgir §11).
 *
 * "Supply a secure customer-scoped saved-payment-method picker... Validate
 * payment-method ownership and subscription/customer/provider scope on the
 * server. Do not accept arbitrary token text as sufficient authorization."
 *
 * The scope half turned out to matter more than the picker: these actions
 * loaded a subscription by bare id with no business check at all, so a
 * provider could charge or cancel another business's subscriber.
 */

use App\Models\ClassProgram;
use App\Models\ClassSubscription;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'provider_admin'] as $r) {
        Role::findOrCreate($r, 'web');
    }
});

function provider(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('provider_admin');

    return $u->fresh();
}

/** A programme owned by $owner, with one subscriber. */
function subscriptionOf(User $owner, ?User $customer = null): ClassSubscription
{
    $customer ??= User::factory()->create();
    $program = ClassProgram::create([
        'name' => 'Padel Club', 'status' => 'active', 'owner_user_id' => $owner->id,
        'mapped_owner_user_id' => $owner->id, 'price' => 100, 'currency' => 'QAR',
    ]);

    return ClassSubscription::create([
        'uuid' => (string) Str::uuid(), 'program_id' => $program->id,
        'customer_id' => $customer->uuid, 'status' => 'active',
        'monthly_amount' => 100, 'currency' => 'QAR',
    ]);
}

it('lists only that customer own saved cards, and never the Stripe token', function () {
    $owner = provider();
    $customer = User::factory()->create();
    $stranger = User::factory()->create();
    $sub = subscriptionOf($owner, $customer);

    PaymentMethod::create([
        'user_id' => $customer->id, 'brand' => 'visa', 'last_four' => '4242',
        'exp_month' => 12, 'exp_year' => 2030, 'stripe_payment_method_id' => 'pm_SECRET', 'default' => true,
    ]);
    PaymentMethod::create([
        'user_id' => $stranger->id, 'brand' => 'mastercard', 'last_four' => '9999',
        'exp_month' => 1, 'exp_year' => 2031, 'stripe_payment_method_id' => 'pm_OTHER',
    ]);

    $res = $this->actingAs($owner)
        ->getJson("/api/admin/subscriptions/dashboard/{$sub->id}/payment-methods")->assertOk();

    $methods = $res->json('data.payment_methods');

    expect($methods)->toHaveCount(1)
        ->and($methods[0]['last_four'])->toBe('4242')
        // The token stays on the server; the dashboard picks by our own id.
        ->and(json_encode($methods))->not->toContain('pm_SECRET')
        ->and(json_encode($methods))->not->toContain('9999');
});

it('refuses a saved card that belongs to someone else', function () {
    $owner = provider();
    $sub = subscriptionOf($owner);
    $strangersCard = PaymentMethod::create([
        'user_id' => User::factory()->create()->id, 'brand' => 'visa', 'last_four' => '1111',
        'exp_month' => 12, 'exp_year' => 2030, 'stripe_payment_method_id' => 'pm_x',
    ]);

    $this->actingAs($owner)
        ->postJson("/api/admin/subscriptions/dashboard/{$sub->id}/charge",
            ['payment_method_id' => $strangersCard->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_method_id');
});

it('does not accept raw token text as authorisation', function () {
    $owner = provider();
    $sub = subscriptionOf($owner);

    // A Stripe-looking string is not an id of anything this customer owns.
    $this->actingAs($owner)
        ->postJson("/api/admin/subscriptions/dashboard/{$sub->id}/charge",
            ['payment_method_id' => 'pm_live_whatever'])
        ->assertStatus(422);
});

it('will not let one provider charge another business subscriber', function () {
    $mine = provider();
    $theirs = provider();
    $sub = subscriptionOf($theirs);          // not mine

    // Same 404 as a missing row: confirming it exists is itself a leak.
    $this->actingAs($mine)
        ->postJson("/api/admin/subscriptions/dashboard/{$sub->id}/charge", [])
        ->assertStatus(404);
});

it('will not let one provider cancel or read another business subscriber', function () {
    $mine = provider();
    $sub = subscriptionOf(provider());

    $this->actingAs($mine)->getJson("/api/admin/subscriptions/dashboard/{$sub->id}/payment-methods")->assertStatus(404);
    $this->actingAs($mine)->postJson("/api/admin/subscriptions/dashboard/{$sub->id}/cancel", [])->assertStatus(404);
    $this->actingAs($mine)->postJson("/api/admin/subscriptions/dashboard/{$sub->id}/pause", [])->assertStatus(404);
});

it('does not let a bulk action reach what the single-id endpoints refuse', function () {
    $mine = provider();
    $sub = subscriptionOf(provider());

    $this->actingAs($mine)
        ->postJson('/api/admin/subscriptions/dashboard/bulk-cancel', ['ids' => [$sub->id]])
        ->assertOk();

    // Untouched: a bulk endpoint must not be the way around the scope check.
    expect($sub->fresh()->status)->toBe('active');
});

it('still lets the owning provider act on their own subscriber', function () {
    $owner = provider();
    $sub = subscriptionOf($owner);

    $this->actingAs($owner)
        ->getJson("/api/admin/subscriptions/dashboard/{$sub->id}/payment-methods")
        ->assertOk();
});

it('leaves platform admins unscoped', function () {
    $platform = User::factory()->create(['status' => User::ACTIVE]);
    $platform->assignRole('super_admin');
    $sub = subscriptionOf(provider());

    $this->actingAs($platform->fresh())
        ->getJson("/api/admin/subscriptions/dashboard/{$sub->id}/payment-methods")
        ->assertOk();
});
