<?php

/**
 * The one-shot backfill in 2026_10_08_000001_create_business_customer_table.
 *
 * It runs once against live data and cannot be re-tried per-row, so each of its three
 * sources gets a case here. The migration's create step is guarded by hasTable(), so
 * calling up() again on a migrated database re-runs just the backfill — which is what
 * these tests do, against history built by hand.
 */

use App\Models\Booking;
use App\Models\BusinessCustomer;
use App\Models\ClassProgram;
use App\Models\ClassSubscription;
use App\Models\LoyaltyTier;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Re-run only the backfill statements of the migration. */
function runBackfill(): void
{
    BusinessCustomer::query()->delete();

    $migration = require base_path('database/migrations/2026_10_08_000001_create_business_customer_table.php');
    $migration->up();
}

beforeEach(function () {
    $this->seed(LoyaltyTierTableSeeder::class);
    $bronze = (int) LoyaltyTier::where('slug', 'bronze')->value('id');

    $this->mk = function (string $email, string $role, array $extra = []) use ($bronze): User {
        Role::findOrCreate($role, 'web');
        $u = User::factory()->create($extra + [
            'email' => $email, 'status' => User::ACTIVE, 'loyalty_tier_id' => $bronze,
        ]);
        $u->assignRole($role);

        return $u->fresh();
    };

    $this->provA = ($this->mk)('prov-a@test.local', 'provider_admin');
    $this->venA  = Venue::create(['name' => 'Venue A', 'owner_user_id' => $this->provA->id]);
});

it('backfills a customer who booked at the business\'s venue', function () {
    $booker = ($this->mk)('booker@test.local', 'customer');
    Booking::create([
        'user_id' => $booker->id, 'venue_id' => $this->venA->id,
        'subtotal' => 10, 'total_price' => 10,
    ]);

    runBackfill();

    expect(BusinessCustomer::where('user_id', $booker->id)
        ->where('owner_user_id', $this->provA->id)
        ->where('source', 'booked')->exists())->toBeTrue();
});

it('backfills a programme subscriber, matching the email case-insensitively', function () {
    $member  = ($this->mk)('member@test.local', 'customer');
    $program = ClassProgram::create([
        'uuid' => (string) Str::uuid(), 'name' => 'Program A',
        'venue_id' => $this->venA->id, 'mapped_owner_user_id' => $this->provA->id,
    ]);
    ClassSubscription::create([
        'uuid' => (string) Str::uuid(), 'program_id' => $program->id,
        'customer_email' => 'MEMBER@test.local',
    ]);

    runBackfill();

    expect(BusinessCustomer::where('user_id', $member->id)
        ->where('owner_user_id', $this->provA->id)
        ->where('source', 'subscribed')->exists())->toBeTrue();
});

it('backfills a programme whose venue was removed, via its owner', function () {
    $member  = ($this->mk)('orphaned@test.local', 'customer');
    $program = ClassProgram::create([
        'uuid' => (string) Str::uuid(), 'name' => 'Ownerless Venue Program',
        'venue_id' => null, 'mapped_owner_user_id' => $this->provA->id,
    ]);
    ClassSubscription::create([
        'uuid' => (string) Str::uuid(), 'program_id' => $program->id,
        'customer_email' => 'orphaned@test.local',
    ]);

    runBackfill();

    expect(BusinessCustomer::where('user_id', $member->id)
        ->where('owner_user_id', $this->provA->id)->exists())->toBeTrue();
});

it('backfills a client typed in by the owner, from created_by', function () {
    $client = ($this->mk)('typed-by-owner@test.local', 'customer', ['created_by' => $this->provA->id]);

    runBackfill();

    expect(BusinessCustomer::where('user_id', $client->id)
        ->where('owner_user_id', $this->provA->id)
        ->where('source', 'added')->exists())->toBeTrue();
});

it('attributes a client typed in by staff to the owner, not the staff member', function () {
    $staff  = ($this->mk)('staff@test.local', 'staff', ['owner_user_id' => $this->provA->id]);
    $client = ($this->mk)('typed-by-staff@test.local', 'customer', ['created_by' => $staff->id]);

    runBackfill();

    expect(BusinessCustomer::where('user_id', $client->id)
        ->where('owner_user_id', $this->provA->id)->exists())->toBeTrue();

    expect(BusinessCustomer::where('owner_user_id', $staff->id)->exists())->toBeFalse();
});

it('attributes nothing to a platform admin who created a customer', function () {
    $super  = ($this->mk)('super@test.local', 'super_admin');
    $client = ($this->mk)('typed-by-super@test.local', 'customer', ['created_by' => $super->id]);

    runBackfill();

    expect(BusinessCustomer::where('user_id', $client->id)->exists())->toBeFalse();
});

it('never links a user to themselves', function () {
    $selfMade = ($this->mk)('self@test.local', 'customer');
    $selfMade->forceFill(['created_by' => $selfMade->id])->save();

    runBackfill();

    expect(BusinessCustomer::where('owner_user_id', $selfMade->id)
        ->where('user_id', $selfMade->id)->exists())->toBeFalse();
});

it('is idempotent — running it twice adds no duplicates', function () {
    $booker = ($this->mk)('booker@test.local', 'customer');
    Booking::create([
        'user_id' => $booker->id, 'venue_id' => $this->venA->id,
        'subtotal' => 10, 'total_price' => 10,
    ]);

    runBackfill();
    $after = BusinessCustomer::count();

    // Second pass WITHOUT clearing, the way a re-run in production would behave.
    $migration = require base_path('database/migrations/2026_10_08_000001_create_business_customer_table.php');
    $migration->up();

    expect(BusinessCustomer::count())->toBe($after);
});

it('records both links when two businesses share a customer', function () {
    $provB = ($this->mk)('prov-b@test.local', 'provider_admin');
    $venB  = Venue::create(['name' => 'Venue B', 'owner_user_id' => $provB->id]);

    $shared = ($this->mk)('shared@test.local', 'customer');
    Booking::create(['user_id' => $shared->id, 'venue_id' => $this->venA->id, 'subtotal' => 10, 'total_price' => 10]);
    Booking::create(['user_id' => $shared->id, 'venue_id' => $venB->id, 'subtotal' => 10, 'total_price' => 10]);

    runBackfill();

    expect(BusinessCustomer::where('user_id', $shared->id)->pluck('owner_user_id')->all())
        ->toEqualCanonicalizing([$this->provA->id, $provB->id]);
});
