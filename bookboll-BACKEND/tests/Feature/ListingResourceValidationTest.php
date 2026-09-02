<?php

/**
 * A listing must not promise more concurrent bookings than its shared resource
 * pool can physically supply.
 *
 * Found on 2026-09-02: "6v6 Half Court - Abu Hamor" was saved with
 * max_bookings_per_slot = 2 while each booking consumes 2 units of a 2-unit
 * pool, so only 1 booking could ever run. The availability code now reports
 * the true 1, but the configuration should never have been accepted.
 */

use App\Models\Resource;
use App\Services\ListingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** Run the listing validator over a resource requirement + ceiling combination. */
function validateCeiling(int $poolSize, int $needsPerBooking, int $maxPerSlot): ?array
{
    // resources.name is unique, and one test validates several combinations.
    $resource = Resource::create([
        'name'           => "Court {$poolSize}/{$needsPerBooking}/{$maxPerSlot}",
        'status'         => 'active',
        'unit_type'      => 'court',
        'total_quantity' => $poolSize,
    ]);

    $method = new ReflectionMethod(ListingService::class, 'validateComplexData');
    $method->setAccessible(true);

    try {
        $method->invoke(app(ListingService::class), [
            'payment_options'       => ['cash_on_arrival' => '0'],
            'max_bookings_per_slot' => $maxPerSlot,
            'resources'             => [['id' => $resource->id, 'quantity' => $needsPerBooking]],
            'schedule'              => [['day' => 'monday', 'is_open' => false]],
        ], isUpdate: true);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('rejects a ceiling the shared pool can never reach', function () {
    // Each booking takes 2 of 2 courts, so only 1 can ever run.
    $errors = validateCeiling(poolSize: 2, needsPerBooking: 2, maxPerSlot: 2);

    expect($errors)->toHaveKey('max_bookings_per_slot')
        ->and($errors['max_bookings_per_slot'][0])->toContain('at most 1 can run at once');
});

it('accepts the ceiling the pool can actually reach', function () {
    expect(validateCeiling(poolSize: 2, needsPerBooking: 2, maxPerSlot: 1))->toBeNull();
});

it('accepts the Al Sadd badminton and pickleball configuration', function () {
    // The live setup that exposed the availability bug is itself valid.
    expect(validateCeiling(poolSize: 6, needsPerBooking: 1, maxPerSlot: 6))->toBeNull()
        ->and(validateCeiling(poolSize: 6, needsPerBooking: 1, maxPerSlot: 3))->toBeNull();
});

it('still rejects a requirement larger than the whole pool', function () {
    $errors = validateCeiling(poolSize: 2, needsPerBooking: 5, maxPerSlot: 1);

    // The pre-existing stock check fires, and does not double-report as a ceiling error.
    expect($errors)->toHaveKey('resources.0.quantity')
        ->and($errors)->not->toHaveKey('max_bookings_per_slot');
});
