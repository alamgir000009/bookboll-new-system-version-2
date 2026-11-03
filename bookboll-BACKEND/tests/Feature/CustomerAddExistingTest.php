<?php

/**
 * Adding a customer whose account already exists.
 *
 * A customer is one global `users` row; which businesses they belong to is a
 * relationship (business_customer, bookings, subscriptions). So when business B
 * types in the email of someone business A already has, the right answer is "link
 * B to that person", not "email already taken" — which is what both add endpoints
 * used to say, leaving B unable to reach a customer it plainly knows.
 */

use App\Models\BusinessCustomer;
use App\Models\LoyaltyTier;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);
    $bronze = (int) LoyaltyTier::where('slug', 'bronze')->value('id');

    $this->mk = function (string $email, string $role, array $extra = []) use ($bronze): User {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create(array_merge([
            'email'           => $email,
            'status'          => User::ACTIVE,
            'loyalty_tier_id' => $bronze,
        ], $extra));
        $u->assignRole($role);

        return $u->fresh();
    };

    $this->provA = ($this->mk)('prov-a@test.local', 'provider_admin');
    $this->provB = ($this->mk)('prov-b@test.local', 'provider_admin');
    $this->super = ($this->mk)('super@test.local', 'super_admin');
    Venue::create(['name' => 'Venue A', 'owner_user_id' => $this->provA->id]);
    Venue::create(['name' => 'Venue B', 'owner_user_id' => $this->provB->id]);

    // A's hand-added client, with a profile of their own.
    $this->client = ($this->mk)('shared@test.local', 'customer', ['name' => 'Original Name', 'phone' => '+97450000001']);
    BusinessCustomer::link($this->provA->id, $this->client->id, BusinessCustomer::SOURCE_ADDED);
});

function linked(User $business, User $customer): bool
{
    return BusinessCustomer::where('owner_user_id', $business->id)->where('user_id', $customer->id)->exists();
}

/* ─────────────── register endpoint (admin app / booking drawer) ─────────────── */

it('links an existing customer to a second business instead of refusing', function () {
    Mail::fake();

    $res = $this->actingAs($this->provB)->postJson('/api/admin/customers/register', [
        'name'  => 'Typed By B',
        'email' => 'shared@test.local',
        'phone' => '+97499999999',
    ]);

    $res->assertOk()
        ->assertJsonPath('data.linked_existing', true)
        ->assertJsonPath('data.already_linked', false)
        ->assertJsonPath('data.user.id', $this->client->id);

    expect(linked($this->provB, $this->client))->toBeTrue()
        ->and(linked($this->provA, $this->client))->toBeTrue()
        ->and(User::where('email', 'shared@test.local')->count())->toBe(1);

    // The account is the customer's: nothing B typed overwrites it, and no welcome
    // email goes out for an account that already exists.
    $fresh = $this->client->fresh();
    expect($fresh->name)->toBe('Original Name')
        ->and($fresh->phone)->toBe('+97450000001')
        ->and($fresh->password)->toBe($this->client->password);
    Mail::assertNothingSent();

    // And B can now actually see them.
    $emails = collect($this->actingAs($this->provB)->getJson('/api/admin/customers/list?per_page=50')
        ->json('data.customers.data'))->pluck('email')->all();
    expect($emails)->toContain('shared@test.local');
});

it('matches the email case-insensitively', function () {
    $this->actingAs($this->provB)->postJson('/api/admin/customers/register', [
        'email' => 'Shared@Test.Local',
    ])->assertOk()->assertJsonPath('data.user.id', $this->client->id);

    expect(User::count())->toBe(4);
});

it('reports an already linked customer as already in your clients, without a duplicate row', function () {
    $this->actingAs($this->provA)->postJson('/api/admin/customers/register', [
        'email' => 'shared@test.local',
    ])->assertOk()
        ->assertJsonPath('data.linked_existing', true)
        ->assertJsonPath('data.already_linked', true);

    expect(BusinessCustomer::where('user_id', $this->client->id)->where('owner_user_id', $this->provA->id)->count())->toBe(1);
});

it('gives a non-customer account the customer role and links it', function () {
    $staff = ($this->mk)('staff-of-a@test.local', 'staff', ['owner_user_id' => $this->provA->id]);

    $this->actingAs($this->provB)->postJson('/api/admin/customers/register', [
        'email' => 'staff-of-a@test.local',
    ])->assertOk()->assertJsonPath('data.linked_existing', true);

    expect($staff->fresh()->hasRole('customer'))->toBeTrue()
        ->and(linked($this->provB, $staff))->toBeTrue();
});

it('still treats an existing customer as a duplicate for a global admin, who has no business to link', function () {
    $this->actingAs($this->super)->postJson('/api/admin/customers/register', [
        'email' => 'shared@test.local',
    ])->assertStatus(400);

    expect(BusinessCustomer::where('user_id', $this->client->id)->count())->toBe(1);
});

it('still creates a brand-new customer the way it always did', function () {
    Mail::fake();

    $this->actingAs($this->provB)->postJson('/api/admin/customers/register', [
        'name' => 'Brand New', 'email' => 'new@test.local',
    ])->assertOk()->assertJsonPath('data.linked_existing', false);

    $new = User::where('email', 'new@test.local')->firstOrFail();
    expect($new->hasRole('customer'))->toBeTrue()
        ->and(linked($this->provB, $new))->toBeTrue()
        ->and($new->marketing_email)->toBeTrue();
    Mail::assertSent(\App\Mail\UserWelcomeEmail::class);
});

/* ─────────────── dashboard endpoint (POST /customers) ─────────────── */

it('links an existing customer from the dashboard add form too', function () {
    $res = $this->actingAs($this->provB)->postJson('/api/admin/customers', [
        'full_name' => 'Typed By B',
        'email'     => 'shared@test.local',
    ]);

    $res->assertOk()
        ->assertJsonPath('data.linked_existing', true)
        ->assertJsonPath('data.customer.email', 'shared@test.local');

    expect(linked($this->provB, $this->client))->toBeTrue()
        ->and($this->client->fresh()->name)->toBe('Original Name')
        ->and(User::where('email', 'shared@test.local')->count())->toBe(1);
});

it('opts a dashboard-added client in to every channel when the form sends no flags', function () {
    $this->actingAs($this->provA)->postJson('/api/admin/customers', [
        'full_name' => 'No Flags', 'email' => 'noflags@test.local',
    ])->assertCreated();

    $u = User::where('email', 'noflags@test.local')->firstOrFail();
    expect($u->marketing_email)->toBeTrue()
        ->and($u->marketing_sms)->toBeTrue()
        ->and($u->marketing_whatsapp)->toBeTrue()
        ->and($u->preferences->notifications['marketing_emails'])->toBeTrue();
});

it('keeps an explicit opt-out sent by the dashboard form', function () {
    $this->actingAs($this->provA)->postJson('/api/admin/customers', [
        'full_name' => 'Opted Out', 'email' => 'optout@test.local', 'marketing_email' => false,
    ])->assertCreated();

    $u = User::where('email', 'optout@test.local')->firstOrFail();
    expect($u->marketing_email)->toBeFalse()
        ->and($u->marketing_sms)->toBeTrue()
        ->and($u->preferences->notifications['marketing_emails'])->toBeFalse();
});

it('still refuses an update that would take another account\'s email', function () {
    $other = ($this->mk)('other-of-a@test.local', 'customer');
    BusinessCustomer::link($this->provA->id, $other->id);

    $this->actingAs($this->provA)->putJson("/api/admin/customers/{$other->id}", [
        'email' => 'shared@test.local',
    ])->assertStatus(422);
});
