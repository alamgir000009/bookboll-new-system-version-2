<?php

/**
 * Signed landlord contracts (readmeAlamgir §2).
 *
 * "Accept PDF/JPEG/PNG <=10 MB. Server verifies type/signature, scans,
 * randomizes filenames and stores privately. Document must be owned by this
 * venue/scope. Temporary unlinked documents expire. No public image-upload
 * service or permanent public URLs."
 */

use App\Models\User;
use App\Models\Venue;
use App\Models\VenueContractDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Role::findOrCreate('super_admin', 'web'));

function admin2(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('super_admin');

    return $u->fresh();
}

function operatedVenue(): Venue
{
    return Venue::create([
        'name' => 'Operated', 'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=0,0',
        'operator_type' => 'bookboll', 'owner_user_id' => null, 'currency_override' => 'QAR',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

/** A file whose BYTES are a real PDF, whatever it is named. */
function pdfFile(string $name = 'lease.pdf'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'c').'.pdf';
    file_put_contents($path, "%PDF-1.7\n".str_repeat('x', 200));

    return new UploadedFile($path, $name, 'application/pdf', null, true);
}

function upload(User $a, Venue $v, UploadedFile $f, ?string $key = null)
{
    return test()->actingAs($a)->post("/api/admin/venues/{$v->id}/contracts", [
        'file' => $f, 'idempotency_key' => $key ?: 'k-'.uniqid(),
    ], ['Accept' => 'application/json']);
}

it('stores a contract privately, under a randomised name', function () {
    $v = operatedVenue();
    $res = upload(admin2(), $v, pdfFile('Landlord Lease.pdf'))->assertOk();

    $doc = VenueContractDocument::first();

    expect($res->json('data.document_id'))->toBe((string) $doc->id)
        ->and($doc->disk)->toBe('local')                      // private disk
        ->and($doc->verified_type)->toBe('pdf')
        ->and($doc->original_name)->toBe('Landlord Lease.pdf') // display only
        // The stored path uses a generated id, not the uploaded name.
        ->and($doc->path)->not->toContain('Landlord')
        ->and($doc->path)->toStartWith("contracts/venues/{$v->id}/")
        // Nothing public anywhere in the response.
        ->and(json_encode($res->json()))->not->toContain('http');

    Storage::disk('local')->assertExists($doc->path);
});

it('judges the file by its bytes, not its name or content type', function () {
    // A script wearing a .pdf extension and a PDF content type.
    $path = tempnam(sys_get_temp_dir(), 'bad').'.pdf';
    file_put_contents($path, '<?php echo "not a pdf"; ?>');
    $disguised = new UploadedFile($path, 'contract.pdf', 'application/pdf', null, true);

    upload(admin2(), operatedVenue(), $disguised)
        ->assertStatus(422)->assertJsonValidationErrors('file');

    expect(VenueContractDocument::count())->toBe(0);
});

it('arrives unlinked, so an abandoned upload expires', function () {
    upload(admin2(), operatedVenue(), pdfFile())->assertOk();

    expect(VenueContractDocument::first()->isTemporary())->toBeTrue();
});

it('prunes abandoned uploads and removes their files', function () {
    $doc = VenueContractDocument::create([
        'venue_id' => operatedVenue()->id, 'disk' => 'local',
        'path' => 'contracts/venues/1/abandoned.pdf', 'original_name' => 'x.pdf',
    ]);
    // created_at is not fillable, so age it explicitly.
    $doc->forceFill(['created_at' => now()->subDays(5)])->save();
    Storage::disk('local')->put($doc->path, '%PDF-1.7');

    $this->artisan('contracts:prune-temporary')->assertExitCode(0);

    expect(VenueContractDocument::count())->toBe(0);
    Storage::disk('local')->assertMissing($doc->path);
});

it('never prunes a contract an agreement actually cites', function () {
    $doc = VenueContractDocument::create([
        'venue_id' => operatedVenue()->id, 'disk' => 'local',
        'path' => 'contracts/venues/1/in-use.pdf', 'original_name' => 'y.pdf',
        'linked_at' => now()->subYear(),             // old, but in use
    ]);
    $doc->forceFill(['created_at' => now()->subYear()])->save();

    $this->artisan('contracts:prune-temporary')->assertExitCode(0);

    expect(VenueContractDocument::whereKey($doc->id)->exists())->toBeTrue();
});

it('links the contract once an agreement cites it', function () {
    $a = admin2();
    $v = operatedVenue();
    $docId = upload($a, $v, pdfFile())->json('data.document_id');

    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/operating-agreements", [
        'deal_type' => 'fixed_rent', 'currency' => 'QAR', 'landlord_name' => 'Landlord Co',
        'monthly_rent' => 3100, 'effective_from' => '2026-01-01', 'change_type' => 'initial',
        'expected_version' => 0, 'contract_document_id' => $docId,
        'idempotency_key' => 'a-'.uniqid(),
    ])->assertOk();

    expect(VenueContractDocument::find($docId)->isTemporary())->toBeFalse();
});

it('refuses a contract belonging to another venue', function () {
    $a = admin2();
    $mine = operatedVenue();
    $theirs = operatedVenue();
    $foreignDoc = upload($a, $theirs, pdfFile())->json('data.document_id');

    $this->actingAs($a)->postJson("/api/admin/venues/{$mine->id}/operating-agreements", [
        'deal_type' => 'fixed_rent', 'currency' => 'QAR', 'landlord_name' => 'L',
        'monthly_rent' => 3100, 'effective_from' => '2026-01-01', 'change_type' => 'initial',
        'expected_version' => 0, 'contract_document_id' => $foreignDoc,
        'idempotency_key' => 'b-'.uniqid(),
    ])->assertStatus(422)->assertJsonValidationErrors('contract_document_id');
});

it('returns the same document when an upload is retried', function () {
    $a = admin2();
    $v = operatedVenue();
    $key = 'same-upload';

    $one = upload($a, $v, pdfFile(), $key)->assertOk()->json('data.document_id');
    $two = upload($a, $v, pdfFile(), $key)->assertOk()->json('data.document_id');

    expect($two)->toBe($one)->and(VenueContractDocument::count())->toBe(1);
});

it('refuses contracts for a venue Bookboll does not operate', function () {
    $v = operatedVenue();
    $v->update(['operator_type' => 'provider', 'owner_user_id' => User::factory()->create()->id]);

    upload(admin2(), $v, pdfFile())->assertStatus(422);
});
