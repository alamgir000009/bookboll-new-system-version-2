<?php

/**
 * Booking requires a login, and the customer login used to resolve the account with
 * a role-scoped lookup:
 *
 *     ->whereRelation('roles', 'name', 'customer')->whereEmail($email)
 *
 * A business owner holds provider roles and no customer role, so that query returned
 * null and their correct password was answered with "Invalid credentials" — they could
 * not book on the platform they sell on. register() has always handled this case: it
 * finds the account by email, proves ownership with Hash::check, then grants the
 * customer role. These tests pin the same rule on the login path, including the two
 * places it must NOT widen — a self-deleted account, and reset mail for a passwordless
 * non-customer account.
 */

use App\Models\DeletedAccount;
use App\Models\LoyaltyTier;
use App\Models\User;
use App\Mail\CustomerForgotPasswordOtpEmail;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // User::$appends carries next_tier, which walks the tier ladder and null-derefs
    // when no tiers exist — the login response serialises the user, so an unseeded
    // database 500s before the assertion is ever reached.
    $this->seed(LoyaltyTierTableSeeder::class);

    $this->bronze = (int) LoyaltyTier::where('slug', 'bronze')->value('id');

    foreach (['customer', 'business_owner'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->makeUser = function (string $email, array $roles, ?string $password = 'secret-pass-1'): User {
        $user = User::create([
            'name'            => 'Test User',
            'email'           => $email,
            'phone'           => '+97450000000',
            'password'        => $password,
            'status'          => User::ACTIVE,
            'loyalty_tier_id' => $this->bronze,
        ]);

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        return $user->refresh();
    };
});

it('lets a business owner log in to book and grants them the customer side', function () {
    $owner = ($this->makeUser)('owner@bookboll.test', ['business_owner']);

    expect($owner->hasRole('customer'))->toBeFalse();

    $res = $this->postJson('/api/customer/login', [
        'email'    => 'owner@bookboll.test',
        'password' => 'secret-pass-1',
    ]);

    $res->assertOk();
    expect($res->json('data.token'))->not->toBeEmpty();

    $owner->refresh();
    expect($owner->hasRole('customer'))->toBeTrue()
        ->and($owner->hasRole('business_owner'))->toBeTrue()   // keeps what it had
        ->and($owner->preferences)->not->toBeNull();
});

it('refuses a wrong password and grants nothing', function () {
    $owner = ($this->makeUser)('owner2@bookboll.test', ['business_owner']);

    $this->postJson('/api/customer/login', [
        'email'    => 'owner2@bookboll.test',
        'password' => 'not-the-password',
    ])->assertJson(['success' => false]);

    expect($owner->refresh()->hasRole('customer'))->toBeFalse();
});

it('still logs in a plain customer', function () {
    ($this->makeUser)('cust@bookboll.test', ['customer']);

    $res = $this->postJson('/api/customer/login', [
        'email'    => 'cust@bookboll.test',
        'password' => 'secret-pass-1',
    ]);

    $res->assertOk();
    expect($res->json('data.token'))->not->toBeEmpty();
});

it('does not revive an account the holder deleted, even on the right password', function () {
    $gone = ($this->makeUser)('gone@bookboll.test', []);

    DeletedAccount::create([
        'user_id' => $gone->id,
        'name'    => $gone->name,
        'email'   => $gone->email,
        'phone'   => $gone->phone,
        'source'  => DeletedAccount::SOURCE_CUSTOMER_MOBILE_APP,
    ]);

    $this->postJson('/api/customer/login', [
        'email'    => 'gone@bookboll.test',
        'password' => 'secret-pass-1',
    ])->assertJson(['success' => false]);

    expect($gone->refresh()->hasRole('customer'))->toBeFalse();
});

it('will not send reset mail for a passwordless non-customer account', function () {
    Mail::fake();

    ($this->makeUser)('staff@bookboll.test', ['business_owner'], null);

    $this->postJson('/api/customer/login', [
        'email'    => 'staff@bookboll.test',
        'password' => 'anything',
    ])->assertJson(['success' => false]);

    Mail::assertNothingSent();
});
