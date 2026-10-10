<?php

/**
 * Forgot-password for the admin DASHBOARD (apps/admin), which had none — staff and
 * business owners who lost their password had to be reset by hand.
 *
 * A six-digit code by email rather than a reset link, so there is no page to route to
 * and no link host to tamper with. What these tests mostly pin is who may use it and
 * how hard it is to abuse, because this endpoint sets the password on accounts that
 * can refund money and read every customer.
 */

use App\Mail\CustomerForgotPasswordOtpEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    foreach (['super_admin', 'provider_admin', 'guard', 'customer'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

function adminWithRole(string $role, array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'email'    => 'someone@bookboll.com',
        'password' => 'old-password-123',
        'status'   => User::ACTIVE,
    ], $attributes));
    $user->assignRole($role);

    return $user->fresh();
}

/** Request a code and read it back out of the email that was sent. */
function requestCodeFor(string $email): ?string
{
    test()->postJson('/api/admin/forgot-password', ['email' => $email])->assertOk();

    $otp = null;
    Mail::assertSent(CustomerForgotPasswordOtpEmail::class, function ($mail) use (&$otp) {
        $otp = $mail->otp;
        return true;
    });

    return $otp;
}

function submitReset(string $email, string $otp, string $password = 'brand-new-password'): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/admin/reset-password', [
        'email'                 => $email,
        'otp'                   => $otp,
        'password'              => $password,
        'password_confirmation' => $password,
    ]);
}

it('resets a staff password end to end', function () {
    $admin = adminWithRole('super_admin');

    $otp = requestCodeFor($admin->email);
    expect($otp)->toMatch('/^\d{6}$/');

    submitReset($admin->email, $otp)->assertOk();

    // The only proof that counts: the new password signs in, the old one does not.
    $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'brand-new-password'])
        ->assertOk()->assertJsonPath('success', true);

    $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'old-password-123'])
        ->assertJsonPath('success', false);
});

it('admits a business owner, not just platform staff', function () {
    // The mobile app's role list covers five roles and omits every provider one.
    // Reusing it here would have told the owners — most of the people who sign in —
    // that they have no account.
    $owner = adminWithRole('provider_admin');

    $otp = requestCodeFor($owner->email);
    submitReset($owner->email, $otp)->assertOk();

    $this->postJson('/api/login', ['email' => $owner->email, 'password' => 'brand-new-password'])
        ->assertJsonPath('success', true);
});

it('says the same thing for an address it does not know', function () {
    $this->postJson('/api/admin/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'If that address belongs to a Bookboll admin account, a reset code is on its way.');

    // Nothing sent and nothing stored — the identical reply is the whole point, so an
    // attacker cannot use this to list which addresses are privileged.
    Mail::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('will not reset a customer-only account', function () {
    $customer = adminWithRole('customer', ['email' => 'player@example.com']);

    $this->postJson('/api/admin/forgot-password', ['email' => $customer->email])->assertOk();
    Mail::assertNothingSent();

    expect($customer->fresh()->passwordMatches('old-password-123'))->toBeTrue();
});

it('will not hand a blocked account back to whoever holds the inbox', function () {
    $blocked = adminWithRole('guard', ['status' => User::BLOCKED]);

    $this->postJson('/api/admin/forgot-password', ['email' => $blocked->email])->assertOk();
    Mail::assertNothingSent();
});

it('rejects an expired code', function () {
    $admin = adminWithRole('super_admin');
    $otp = requestCodeFor($admin->email);

    DB::table('password_reset_tokens')->where('email', $admin->email)
        ->update(['created_at' => now()->subMinutes(11)]);

    submitReset($admin->email, $otp)->assertStatus(422);
    expect($admin->fresh()->passwordMatches('old-password-123'))->toBeTrue();
});

it('kills the code after five wrong guesses', function () {
    // Six digits is a million combinations, and the window is ten minutes long. With
    // no ceiling, an attacker simply enumerates it.
    $admin = adminWithRole('super_admin');
    $otp = requestCodeFor($admin->email);

    $wrong = $otp === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $_) {
        submitReset($admin->email, $wrong)->assertStatus(422);
    }

    // Even the RIGHT code is now worthless — the attempt budget burned the code,
    // not just the attempt.
    submitReset($admin->email, $otp)->assertStatus(429);
    expect($admin->fresh()->passwordMatches('old-password-123'))->toBeTrue();
});

it('signs every existing session out', function () {
    $admin = adminWithRole('super_admin');
    $stolen = $admin->createToken('attacker-session')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$stolen)->getJson('/api/me')->assertOk();

    submitReset($admin->email, requestCodeFor($admin->email))->assertOk();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $admin->id)->count())->toBe(0);

    // The guard caches the user it resolved on the first request of a test, so the
    // stale token has to be re-presented to a guard that has forgotten it — otherwise
    // this asserts the cache, not the database.
    $this->app['auth']->forgetGuards();

    // A reset is what you do when you think you have been compromised. Leaving the
    // other party's token alive would make it theatre.
    $this->withHeader('Authorization', 'Bearer '.$stolen)->getJson('/api/me')->assertStatus(401);
});

it('refuses a password too short to be worth setting', function () {
    $admin = adminWithRole('super_admin');
    $otp = requestCodeFor($admin->email);

    submitReset($admin->email, $otp, 'short')->assertStatus(422);
    expect($admin->fresh()->passwordMatches('old-password-123'))->toBeTrue();
});

it('spends a code once', function () {
    $admin = adminWithRole('super_admin');
    $otp = requestCodeFor($admin->email);

    submitReset($admin->email, $otp)->assertOk();
    submitReset($admin->email, $otp, 'another-password-99')->assertStatus(422);
});
