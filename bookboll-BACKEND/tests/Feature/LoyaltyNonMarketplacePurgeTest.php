<?php

/**
 * Only loyalty earned on bookboll.com survives.
 *
 * Loyalty is the main marketplace's reward for a customer booking there. Two
 * other kinds of booking ran the same earn path and should never have credited
 * anything: one entered by staff from the admin dashboard or admin mobile app,
 * and one taken on a provider's own white-label storefront, where Bookboll
 * takes no commission and so funds no reward.
 *
 * The rule lives on LoyaltyPoint::scopeNotMainMarketplace. The two tests are
 * independent on purpose: a staff-entered booking is still commercially
 * 'marketplace', so the channel alone would miss it, and a customer booking on
 * a white-label site has a customer as its creator, so the creator alone would
 * miss that one.
 *
 * The delete is permanent, so the dry run and the no-booking case are asserted
 * as carefully as the deletion itself.
 */

use App\Models\Booking;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTier;
use App\Models\User;
use App\Models\Venue;
use App\Support\CommissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['admin', 'facility_admin', 'customer'] as $r) {
        Role::findOrCreate($r, 'web');
    }

    // users.loyalty_tier_id is NOT NULL and calculateLoyaltyTier falls back to
    // `bronze`, so a tier has to exist for a balance change to be re-tiered at
    // all — exactly as production is seeded.
    LoyaltyTier::firstOrCreate(['slug' => 'bronze'], [
        'name' => 'Bronze', 'min_points' => 0, 'max_points' => 2147483647,
        'is_active' => true, 'sort_order' => 1,
    ]);
});

function loyaltyBuyer(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('customer');

    return $u->fresh();
}

function loyaltyStaffMember(string $role = 'admin'): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole($role);

    return $u->fresh();
}

function loyaltyBookingFor(User $customer, User $creator, ?string $channel = CommissionPolicy::CHANNEL_MARKETPLACE): Booking
{
    $venue = Venue::create(['name' => 'Court '.uniqid()]);

    return Booking::create([
        'user_id' => $customer->id,
        'created_by' => $creator->id,
        'venue_id' => $venue->id,
        'commission_channel' => $channel,
        'date' => '2026-11-02',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => 'confirmed',
        'subtotal' => 100,
        'total_price' => 100,
    ]);
}

function loyaltyCredit(User $customer, ?Booking $booking, int $points = 10, string $type = 'credit'): LoyaltyPoint
{
    return LoyaltyPoint::create([
        'user_id' => $customer->id,
        'booking_id' => $booking?->id,
        'points' => $points, 'type' => $type, 'reason' => 'earned',
    ]);
}

it('deletes points from a staff-entered booking and keeps the customer’s own', function () {
    $customer = loyaltyBuyer();

    $own = loyaltyCredit($customer, loyaltyBookingFor($customer, $customer), 30);
    $byAdmin = loyaltyCredit($customer, loyaltyBookingFor($customer, loyaltyStaffMember()), 70);

    expect(LoyaltyPoint::balance($customer->id))->toBe(100);

    $this->artisan('loyalty:purge-non-marketplace-points --apply --no-export')->assertSuccessful();

    expect(LoyaltyPoint::find($own->id))->not->toBeNull()
        ->and(LoyaltyPoint::find($byAdmin->id))->toBeNull()
        ->and(LoyaltyPoint::balance($customer->id))->toBe(30);
});

it('deletes points from a white-label booking even when the customer made it', function () {
    $customer = loyaltyBuyer();

    // Customer-created, so only the channel can identify it.
    $onSite = loyaltyCredit($customer, loyaltyBookingFor($customer, $customer, CommissionPolicy::CHANNEL_SITE), 40);
    $onMarketplace = loyaltyCredit($customer, loyaltyBookingFor($customer, $customer), 25);

    $this->artisan('loyalty:purge-non-marketplace-points --apply --no-export')->assertSuccessful();

    expect(LoyaltyPoint::find($onSite->id))->toBeNull()
        ->and(LoyaltyPoint::find($onMarketplace->id))->not->toBeNull()
        ->and(LoyaltyPoint::balance($customer->id))->toBe(25);
});

it('treats any non-customer role as staff, and an unset channel as marketplace', function () {
    $customer = loyaltyBuyer();

    $byManager = loyaltyCredit($customer, loyaltyBookingFor($customer, loyaltyStaffMember('facility_admin')), 40);
    // 17751 live bookings carry no channel at all; those are marketplace.
    $noChannel = loyaltyCredit($customer, loyaltyBookingFor($customer, $customer, null), 15);

    $this->artisan('loyalty:purge-non-marketplace-points --apply --no-export')->assertSuccessful();

    expect(LoyaltyPoint::find($byManager->id))->toBeNull()
        ->and(LoyaltyPoint::find($noChannel->id))->not->toBeNull()
        ->and(LoyaltyPoint::balance($customer->id))->toBe(15);
});

it('never touches a movement with no booking, whose provenance is unknown', function () {
    $customer = loyaltyBuyer();
    $orphan = loyaltyCredit($customer, null, 50);

    $this->artisan('loyalty:purge-non-marketplace-points --apply --no-export')->assertSuccessful();

    expect(LoyaltyPoint::find($orphan->id))->not->toBeNull()
        ->and(LoyaltyPoint::balance($customer->id))->toBe(50);
});

it('deletes nothing on a dry run', function () {
    $customer = loyaltyBuyer();
    $row = loyaltyCredit($customer, loyaltyBookingFor($customer, loyaltyStaffMember()), 55);

    $this->artisan('loyalty:purge-non-marketplace-points')->assertSuccessful();

    expect(LoyaltyPoint::find($row->id))->not->toBeNull()
        ->and(LoyaltyPoint::balance($customer->id))->toBe(55);
});

it('writes every deleted row to a CSV first, since the delete is permanent', function () {
    $customer = loyaltyBuyer();
    $row = loyaltyCredit($customer, loyaltyBookingFor($customer, loyaltyStaffMember()), 65);

    $path = storage_path('app/testing-loyalty-purge.csv');
    @unlink($path);

    $this->artisan("loyalty:purge-non-marketplace-points --apply --export={$path}")->assertSuccessful();

    expect(file_exists($path))->toBeTrue();

    $csv = array_map('str_getcsv', array_filter(file($path)));

    expect($csv[0])->toBe(['id', 'user_id', 'booking_id', 'country_id', 'type', 'points', 'reason', 'created_by', 'created_at'])
        ->and($csv)->toHaveCount(2)
        ->and($csv[1][0])->toBe((string) $row->id)
        ->and($csv[1][5])->toBe('65')
        // The row is gone from the table — the CSV is now the only copy.
        ->and(LoyaltyPoint::find($row->id))->toBeNull();

    @unlink($path);
});

it('is idempotent — a second run finds nothing left', function () {
    $customer = loyaltyBuyer();
    loyaltyCredit($customer, loyaltyBookingFor($customer, loyaltyStaffMember()), 20);

    $this->artisan('loyalty:purge-non-marketplace-points --apply --no-export')->assertSuccessful();
    $this->artisan('loyalty:purge-non-marketplace-points')
        ->expectsOutputToContain('Every loyalty movement already comes from a marketplace booking.')
        ->assertSuccessful();

    expect(LoyaltyPoint::where('user_id', $customer->id)->count())->toBe(0);
});
