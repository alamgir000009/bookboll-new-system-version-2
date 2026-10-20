<?php

/**
 * Venue operating agreements (readmeAlamgir §2, history contract revision 2).
 *
 * Append-only and effective-dated. A change supersedes; it never edits. Rent
 * and revenue share post against service dates, so rewriting an old row would
 * restate months already reported — "A renewal must not mutate already-posted
 * history."
 */

use App\Models\User;
use App\Models\Venue;
use App\Models\VenueOperatingAgreement as Agreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Role::findOrCreate('super_admin', 'web'));

function agreementPlatformAdmin(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('super_admin');

    return $u->fresh();
}

function bookbollVenue(): Venue
{
    return Venue::create([
        'name' => 'Operated Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'operator_type' => 'bookboll', 'owner_user_id' => null,
        'currency_override' => 'QAR', 'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

function saveAgreement(User $a, Venue $v, array $over = [])
{
    return test()->actingAs($a)->postJson("/api/admin/venues/{$v->id}/operating-agreements", array_merge([
        'deal_type' => 'fixed_rent', 'currency' => 'QAR', 'landlord_name' => 'Landlord Co',
        'monthly_rent' => 3100, 'effective_from' => '2026-01-01',
        'change_type' => 'initial', 'expected_version' => 0,
        'idempotency_key' => 'k-'.uniqid(),
    ], $over));
}

it('returns the revision 2 contract the editor requires', function () {
    $res = $this->actingAs(agreementPlatformAdmin())
        ->getJson('/api/admin/venues/'.bookbollVenue()->id.'/operating-agreements')->assertOk();

    expect($res->json('data.contract_version'))->toBe(1)
        ->and($res->json('data.agreement_history_version'))->toBe(2)
        ->and($res->json('data.can_manage'))->toBeTrue()
        ->and($res->json('data.version'))->toBe(0)
        // No agreement is a real answer: landlord cost is UNDEFINED, not zero.
        ->and($res->json('data.current'))->toBeNull()
        ->and($res->json('data.history'))->toBe([]);
});

it('refuses agreements on a venue Bookboll does not operate', function () {
    $v = bookbollVenue();
    $v->update(['operator_type' => 'provider', 'owner_user_id' => User::factory()->create()->id]);

    $this->actingAs(agreementPlatformAdmin())
        ->getJson("/api/admin/venues/{$v->id}/operating-agreements")->assertStatus(422);
});

it('supersedes rather than edits, preserving what the old contract said', function () {
    $a = agreementPlatformAdmin();
    $v = bookbollVenue();

    saveAgreement($a, $v, ['effective_from' => '2026-01-01', 'monthly_rent' => 3100])->assertOk();
    $first = Agreement::first();

    saveAgreement($a, $v, [
        'effective_from' => '2026-10-16', 'monthly_rent' => 6200,
        'change_type' => 'renewal', 'change_reason' => 'Annual renewal',
        'supersedes_agreement_id' => $first->id, 'expected_version' => 1,
    ])->assertOk();

    $first->refresh();

    // The original amount and dates are untouched...
    expect((int) $first->monthly_rent_minor)->toBe(310000)
        ->and($first->effective_from->toDateString())->toBe('2026-01-01')
        ->and($first->effective_to)->toBeNull()
        // ...only its ACTIVE interval was cut, to the day before the successor.
        ->and($first->superseded_on->toDateString())->toBe('2026-10-15')
        ->and($first->activeUntil())->toBe('2026-10-15')
        ->and(Agreement::count())->toBe(2);
});

it('rejects a stale version rather than overwriting someone else edit', function () {
    $a = agreementPlatformAdmin();
    $v = bookbollVenue();
    saveAgreement($a, $v)->assertOk();

    // Still thinks the collection is at 0.
    saveAgreement($a, $v, [
        'effective_from' => '2026-06-01', 'change_type' => 'renewal',
        'change_reason' => 'x', 'expected_version' => 0,
    ])->assertStatus(409);

    expect(Agreement::count())->toBe(1);
});

it('rejects new terms that do not follow the latest version', function () {
    $a = agreementPlatformAdmin();
    $v = bookbollVenue();
    saveAgreement($a, $v)->assertOk();

    saveAgreement($a, $v, [
        'effective_from' => '2026-06-01', 'change_type' => 'renewal', 'change_reason' => 'x',
        'supersedes_agreement_id' => 99999, 'expected_version' => 1,
    ])->assertStatus(409);
});

it('rejects a successor starting on or before the version it replaces', function () {
    $a = agreementPlatformAdmin();
    $v = bookbollVenue();
    saveAgreement($a, $v, ['effective_from' => '2026-05-01'])->assertOk();
    $first = Agreement::first();

    foreach (['2026-05-01', '2026-04-01'] as $bad) {
        saveAgreement($a, $v, [
            'effective_from' => $bad, 'change_type' => 'renewal', 'change_reason' => 'x',
            'supersedes_agreement_id' => $first->id, 'expected_version' => 1,
        ])->assertStatus(409);
    }

    expect(Agreement::count())->toBe(1);
});

it('reports the agreement governing today, not merely the newest', function () {
    $a = agreementPlatformAdmin();
    $v = bookbollVenue();

    saveAgreement($a, $v, ['effective_from' => '2020-01-01'])->assertOk();
    $first = Agreement::first();
    // A FUTURE scheduled term.
    saveAgreement($a, $v, [
        'effective_from' => '2099-01-01', 'change_type' => 'renewal', 'change_reason' => 'future',
        'supersedes_agreement_id' => $first->id, 'expected_version' => 1,
    ])->assertOk();

    $res = $this->actingAs($a)->getJson("/api/admin/venues/{$v->id}/operating-agreements")->assertOk();

    expect($res->json('data.current.effective_from'))->toBe('2020-01-01')
        ->and($res->json('data.history'))->toHaveCount(2);
});

it('requires the fields each deal type cannot be costed without', function (array $case) {
    [$payload, $field] = $case;
    saveAgreement(agreementPlatformAdmin(), bookbollVenue(), $payload)
        ->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'rent with no amount'   => [[['deal_type' => 'fixed_rent', 'monthly_rent' => 0], 'monthly_rent']],
    'rent with no landlord' => [[['deal_type' => 'fixed_rent', 'landlord_name' => ''], 'landlord_name']],
    'share with no percent' => [[['deal_type' => 'revenue_share', 'monthly_rent' => null], 'bookboll_share_percent']],
    'end before start'      => [[['effective_to' => '2025-01-01'], 'effective_to']],
]);

it('accepts a zero percent share, because the landlord may take everything', function () {
    saveAgreement(agreementPlatformAdmin(), bookbollVenue(), [
        'deal_type' => 'revenue_share', 'bookboll_share_percent' => 0, 'monthly_rent' => null,
    ])->assertOk();

    expect((float) Agreement::first()->bookboll_share_percent)->toBe(0.0);
});

it('requires a reason for a renewal but not for the first agreement', function () {
    $a = agreementPlatformAdmin();
    $v = bookbollVenue();
    saveAgreement($a, $v)->assertOk();          // initial, no reason needed
    $first = Agreement::first();

    saveAgreement($a, $v, [
        'effective_from' => '2026-06-01', 'change_type' => 'renewal',
        'supersedes_agreement_id' => $first->id, 'expected_version' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('change_reason');
});

it('returns the same version when a save is retried', function () {
    $a = agreementPlatformAdmin();
    $v = bookbollVenue();
    $key = 'same-key';

    $one = saveAgreement($a, $v, ['idempotency_key' => $key])->assertOk()->json('data.agreement.id');
    $two = saveAgreement($a, $v, ['idempotency_key' => $key])->assertOk()->json('data.agreement.id');

    expect($two)->toBe($one)->and(Agreement::count())->toBe(1);
});
