<?php

/**
 * A new account is opted in to email, SMS and WhatsApp marketing.
 *
 * The users.marketing_* columns default to false in the database, so until
 * User::booted() defaulted them on creation a fresh signup showed as "not opted
 * in" on every channel and campaigns never reached them. These pin that a
 * signup now starts opted in everywhere, while a flag the caller sets
 * explicitly is left alone.
 */

use App\Models\User;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);
    Role::findOrCreate('customer', 'web');
    Mail::fake();
});

it('opts a public signup in to every marketing channel', function () {
    $response = $this->postJson('/api/register', [
        'name'                  => 'New Customer',
        'email'                 => 'new-signup@test.local',
        'phone'                 => '+97455500001',
        'password'              => 'secret123',
        'password_confirmation' => 'secret123',
    ]);

    $response->assertOk();

    $user = User::where('email', 'new-signup@test.local')->firstOrFail();

    expect($user->marketing_email)->toBeTrue()
        ->and($user->marketing_sms)->toBeTrue()
        ->and($user->marketing_whatsapp)->toBeTrue()
        ->and($user->marketing_updated_at)->not->toBeNull()
        ->and($user->hasRole('customer'))->toBeTrue();
});

it('opts any newly created account in by default', function () {
    $user = User::factory()->create()->fresh();

    expect($user->marketing_email)->toBeTrue()
        ->and($user->marketing_sms)->toBeTrue()
        ->and($user->marketing_whatsapp)->toBeTrue();
});

it('keeps a flag the caller set explicitly', function () {
    $user = User::factory()->create([
        'marketing_sms' => false,
    ])->fresh();

    expect($user->marketing_email)->toBeTrue()
        ->and($user->marketing_sms)->toBeFalse()
        ->and($user->marketing_whatsapp)->toBeTrue();
});
