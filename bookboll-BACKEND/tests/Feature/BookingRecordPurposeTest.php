<?php

/**
 * What a booking row IS, said explicitly (readmeAlamgir "Legacy skipped
 * payments are provider slot blocks").
 *
 * Provider slot blocks were entered to reserve a slot for the provider's own
 * customer. They are not unpaid marketplace sales, and every one of them
 * carries origin `bookboll_web` — which is exactly the inference the document
 * forbids relying on.
 */

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Support\BookingPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Role::findOrCreate('super_admin', 'web'));

function purposeAdmin(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('super_admin');

    return $u->fresh();
}

function purposeVenue(): Venue
{
    return Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=0,0',
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

function purposeBooking(Venue $v, array $over = []): Booking
{
    return Booking::create(array_merge([
        'user_id' => User::factory()->create()->id, 'venue_id' => $v->id,
        'date' => '2026-04-10', 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'currency' => 'QAR',
        'status' => 'confirmed', 'payment_status' => 'fully_paid',
        // The origin every legacy row carries, including the blocks.
        'origin' => 'bookboll_web',
    ], $over));
}

it('keeps slot blocks out of sales, even though their origin says bookboll_web', function () {
    $v = purposeVenue();
    purposeBooking($v, ['record_purpose' => BookingPurpose::SALE]);
    purposeBooking($v, [
        'record_purpose' => BookingPurpose::BLOCK,
        'payment_method' => 'payment_skipped', 'payment_status' => 'payment_skipped',
    ]);

    $res = $this->actingAs(purposeAdmin())
        ->getJson('/api/admin/dashboard/analytics/aggregates')->assertOk();

    expect($res->json('data.bookings.eligible'))->toBe(1)
        // Reported as its own figure rather than hidden.
        ->and($res->json('data.provider_slot_blocks'))->toBe(1)
        ->and($res->json('data.sales.0.gross'))->toBe(100);
});

it('classifies a legacy row with no record_purpose by the old inference', function () {
    $v = purposeVenue();
    // Written before the column existed.
    purposeBooking($v, [
        'record_purpose' => null,
        'payment_method' => 'payment_skipped', 'payment_status' => 'payment_skipped',
    ]);
    purposeBooking($v, ['record_purpose' => null]);

    $res = $this->actingAs(purposeAdmin())
        ->getJson('/api/admin/dashboard/analytics/aggregates')->assertOk();

    // A half-migrated database gives the same answer as a migrated one.
    expect($res->json('data.bookings.eligible'))->toBe(1)
        ->and($res->json('data.provider_slot_blocks'))->toBe(1);
});

it('blocks a slot block from venue transfer reconciliation, with its own reason', function () {
    $block = (object) [
        'record_purpose' => BookingPurpose::BLOCK,
        'payment_method' => 'payment_skipped', 'status' => 'completed',
    ];
    $payment = (object) ['type' => 'deposit', 'status' => 'paid', 'amount' => 20, 'currency' => 'QAR'];

    $reason = App\Support\LegacyReconciliation::exclusionFor($payment, $block);

    expect($reason)->toContain('slot block');
});

it('sends mixed evidence to a human instead of deciding by rule', function () {
    // A block that nonetheless carries a real posted payment.
    $mixed = (object) [
        'record_purpose' => BookingPurpose::REVIEW,
        'payment_method' => 'payment_skipped', 'status' => 'completed',
    ];
    $payment = (object) ['type' => 'deposit', 'status' => 'paid', 'amount' => 20, 'currency' => 'QAR'];

    $reason = App\Support\LegacyReconciliation::exclusionFor($payment, $mixed);

    // Excluded AND named as needing review — not silently treated as a block,
    // which would suppress a real ledger entry.
    expect($reason)->toContain('manual review');
});

it('never converts a slot block into a paid sale through reconciliation', function () {
    $v = purposeVenue();
    $b = purposeBooking($v, [
        'record_purpose' => BookingPurpose::BLOCK,
        'payment_method' => 'payment_skipped', 'payment_status' => 'payment_skipped',
    ]);
    Payment::create([
        'booking_id' => $b->id, 'user_id' => $b->user_id, 'payment_id' => 'r-'.uniqid(),
        'type' => 'remaining', 'method' => 'cash', 'status' => 'pending',
        'amount' => 80, 'currency' => 'QAR',
    ]);

    $reason = App\Support\LegacyReconciliation::exclusionFor(
        (object) ['type' => 'remaining', 'status' => 'pending', 'amount' => 80, 'currency' => 'QAR'],
        $b
    );

    expect($reason)->not->toBeNull();
});

it('reports the makeup of the table and flags nothing when evidence is clean', function () {
    $v = purposeVenue();
    purposeBooking($v, ['record_purpose' => BookingPurpose::SALE]);
    purposeBooking($v, ['record_purpose' => BookingPurpose::BLOCK]);

    $this->artisan('bookings:audit-purpose')
        ->expectsOutputToContain('No mixed-evidence rows')
        ->assertExitCode(0);
});

it('warns when mixed evidence exists rather than reporting all clear', function () {
    purposeBooking(purposeVenue(), ['record_purpose' => BookingPurpose::REVIEW]);

    $this->artisan('bookings:audit-purpose')
        ->expectsOutputToContain('need a human decision')
        ->assertExitCode(0);
});
