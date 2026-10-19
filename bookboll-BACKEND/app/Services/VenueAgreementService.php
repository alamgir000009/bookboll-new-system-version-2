<?php

namespace App\Services;

use App\Models\Venue;
use App\Models\VenueOperatingAgreement as Agreement;
use App\Services\BookingWalletService;
use App\Support\AccessScope;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Support\ContractFileType;
use App\Models\VenueContractDocument;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Operating agreements for venues Bookboll runs itself.
 *
 * Append-only. A change writes a new version that supersedes the one before it;
 * nothing historic is edited, because rent and revenue share are posted against
 * service dates and rewriting an old row restates months already reported.
 *
 * The response carries agreement_history_version: 2 — the editor refuses to
 * save against the older contract, which is what stops a half-deployed backend
 * from silently accepting a save it will mishandle.
 */
class VenueAgreementService
{
    public const CONTRACT_VERSION = 1;
    public const HISTORY_VERSION  = 2;

    /** The largest contract this will take, matching the document's limit. */
    public const MAX_CONTRACT_BYTES = 10 * 1024 * 1024;

    /**
     * Store a signed landlord contract, privately.
     *
     * Not an image upload: it never touches the public service, never gets a
     * public URL, and is written to the private disk under a randomised name.
     * The type is read from the file's own first bytes — an extension is chosen
     * by whoever uploads and a content-type header is just a header.
     *
     * The document is created UNLINKED. It becomes a real contract only when an
     * agreement citing it is saved; until then it is temporary and expires, so
     * a failed save leaves an expiring upload rather than a permanent orphan.
     */
    public function storeContract(Request $request, int $venueId)
    {
        [$venue, $actor, $error] = $this->resolve($request, $venueId);
        if ($error) {
            return $error;
        }
        if (! $this->canManage($actor)) {
            return sendError('You cannot manage operating agreements.', [], 403);
        }

        $request->validate([
            'file'            => 'required|file|max:'.(self::MAX_CONTRACT_BYTES / 1024),
            'idempotency_key' => 'required|string|max:120',
        ]);

        $key = (string) $request->input('idempotency_key');
        if ($existing = VenueContractDocument::where('idempotency_key', $key)->first()) {
            return sendSuccess('Contract stored', [
                'contract_version' => self::CONTRACT_VERSION,
                'document_id'      => (string) $existing->id,
                'contract_name'    => $existing->original_name,
            ]);
        }

        $file = $request->file('file');

        // The bytes decide, not the name or the header.
        $type = ContractFileType::detectFile($file->getRealPath());
        if ($type === null) {
            return sendError('That file is not a PDF, JPG or PNG.', [
                'file' => ['Upload the signed contract as a PDF, JPG or PNG.'],
            ], 422);
        }

        $path = sprintf(
            'contracts/venues/%d/%s.%s',
            $venue->id,
            Str::uuid(),
            ContractFileType::extensionFor($type)
        );

        // 'local' is storage/app/private — not web-served, so there is no URL
        // to leak and nothing to guess.
        if (! Storage::disk('local')->put($path, file_get_contents($file->getRealPath()))) {
            return sendError('Could not store that contract. Please try again.', [], 500);
        }

        $doc = VenueContractDocument::create([
            'venue_id'      => $venue->id,
            'uploaded_by'   => $actor->id,
            'disk'          => 'local',
            'path'          => $path,
            // Display only, trimmed; never used to build a path.
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime'          => $file->getClientMimeType(),
            'size_bytes'    => $file->getSize(),
            'verified_type' => $type,
            'idempotency_key' => $key,
        ]);

        return sendSuccess('Contract stored', [
            'contract_version' => self::CONTRACT_VERSION,
            'document_id'      => (string) $doc->id,
            'contract_name'    => $doc->original_name,
        ]);
    }

    public function index(Request $request, int $venueId)
    {
        [$venue, $actor, $error] = $this->resolve($request, $venueId);
        if ($error) {
            return $error;
        }

        $rows = Agreement::where('venue_id', $venue->id)->orderBy('effective_from')->orderBy('version')->get();
        $today = $this->todayAtVenue($venue);

        // "Current" is what governs TODAY in the venue's own timezone — not the
        // newest row, which may be a future scheduled term.
        $current = $rows->first(fn (Agreement $a) => $a->governsOn($today));

        return sendSuccess('Operating agreements', [
            'contract_version'         => self::CONTRACT_VERSION,
            'agreement_history_version' => self::HISTORY_VERSION,
            'can_manage'               => $this->canManage($actor),
            // The collection version the editor sends back as expected_version.
            'version'                  => (int) ($rows->max('version') ?? 0),
            'currencies'               => $this->currencies($venue),
            // Null during a gap is a real answer: landlord cost is UNDEFINED
            // for that period, never zero and never full retention.
            'current'                  => $current ? $this->map($current, $venue) : null,
            'history'                  => $rows->map(fn ($a) => $this->map($a, $venue))->values()->all(),
        ]);
    }

    public function store(Request $request, int $venueId)
    {
        [$venue, $actor, $error] = $this->resolve($request, $venueId);
        if ($error) {
            return $error;
        }
        if (! $this->canManage($actor)) {
            return sendError('You cannot manage operating agreements.', [], 403);
        }

        $data = $request->validate([
            'deal_type'              => ['required', Rule::in(Agreement::DEAL_TYPES)],
            'currency'               => 'required|string|size:3',
            'landlord_name'          => 'nullable|string|max:200',
            'monthly_rent'           => 'nullable|numeric|min:0',
            'bookboll_share_percent' => 'nullable|numeric|min:0|max:100',
            'effective_from'         => 'required|date_format:Y-m-d',
            'effective_to'           => 'nullable|date_format:Y-m-d',
            'change_type'            => ['required', Rule::in(Agreement::CHANGE_TYPES)],
            'change_reason'          => 'nullable|string|max:1000',
            'supersedes_agreement_id' => 'nullable|integer',
            'expected_version'       => 'required|integer|min:0',
            'contract_document_id'   => 'nullable|integer',
            'notes'                  => 'nullable|string|max:2000',
            'idempotency_key'        => 'required|string|max:120',
        ]);

        // A retried save returns the version it already made.
        if ($existing = Agreement::where('idempotency_key', $data['idempotency_key'])->first()) {
            return sendSuccess('Operating agreement saved', [
                'contract_version' => self::CONTRACT_VERSION,
                'agreement'        => $this->map($existing, $venue),
            ]);
        }

        if ($shape = $this->shapeError($data)) {
            return sendError($shape[0], $shape[1], 422);
        }

        // A contract id is only usable if it is THIS venue's. Otherwise an id
        // from another venue's agreement could be attached to this one, which
        // is exactly the "Document must be owned by this venue/scope" rule.
        $document = null;
        if (! empty($data['contract_document_id'])) {
            $document = VenueContractDocument::where('id', $data['contract_document_id'])
                ->where('venue_id', $venue->id)
                ->first();

            if (! $document) {
                return sendError('That contract is not available for this venue.', [
                    'contract_document_id' => ['Upload the contract again.'],
                ], 422);
            }
        }

        return DB::transaction(function () use ($data, $venue, $actor, $document) {
            // Lock the venue's sequence so two saves cannot both believe they
            // are version N.
            $rows = Agreement::where('venue_id', $venue->id)
                ->lockForUpdate()->orderBy('version')->get();

            $version = (int) ($rows->max('version') ?? 0);
            if ((int) $data['expected_version'] !== $version) {
                return sendError(
                    'These terms changed while you were editing them. Reload and review again.',
                    ['expected_version' => ['Stale version.']], 409
                );
            }

            $latest = $rows->last();

            if ($latest) {
                if ((int) ($data['supersedes_agreement_id'] ?? 0) !== (int) $latest->id) {
                    return sendError(
                        'New terms must follow the most recent version.',
                        ['supersedes_agreement_id' => ['Not the latest agreement.']], 409
                    );
                }
                // A successor cannot start on or before what it replaces.
                if ($data['effective_from'] <= $latest->effective_from->toDateString()) {
                    return sendError(
                        'New terms must start after the version they replace.',
                        ['effective_from' => ['Must be after '.$latest->effective_from->toDateString().'.']], 409
                    );
                }
            } elseif (! empty($data['supersedes_agreement_id'])) {
                return sendError('There is no earlier agreement to supersede.', [], 409);
            }

            $currency = strtoupper($data['currency']);
            $agreement = Agreement::create([
                'venue_id'      => $venue->id,
                'version'       => $version + 1,
                'deal_type'     => $data['deal_type'],
                'currency'      => $currency,
                'landlord_name' => $data['deal_type'] === Agreement::DEAL_DIRECT ? null : ($data['landlord_name'] ?? null),
                'monthly_rent_minor' => $data['deal_type'] === Agreement::DEAL_FIXED_RENT
                    ? Money::toMinor($data['monthly_rent'] ?? 0, $currency)
                    : null,
                'bookboll_share_percent' => $data['deal_type'] === Agreement::DEAL_REVENUE_SHARE
                    ? $data['bookboll_share_percent']
                    : null,
                'effective_from' => $data['effective_from'],
                'effective_to'   => $data['effective_to'] ?? null,
                'change_type'    => $data['change_type'],
                'change_reason'  => $data['change_reason'] ?? null,
                'supersedes_agreement_id' => $latest?->id,
                'contract_document_id' => $data['contract_document_id'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => $actor->id,
                'idempotency_key' => $data['idempotency_key'],
            ]);

            if ($latest) {
                // The predecessor's ACTIVE interval ends the day before this
                // one starts. Its contracted dates and amount are untouched —
                // what was agreed stays on the record.
                $latest->forceFill([
                    'superseded_on' => CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString(),
                ])->save();
            }

            // The upload stops being temporary now that an agreement cites it,
            // so the expiry sweep leaves it alone.
            if ($document && $document->isTemporary()) {
                $document->forceFill(['linked_at' => now()])->save();
            }

            return sendSuccess('Operating agreement saved', [
                'contract_version' => self::CONTRACT_VERSION,
                'agreement'        => $this->map($agreement, $venue),
            ]);
        });
    }

    /**
     * Shape rules per deal type. Each exists because the alternative is a row
     * that cannot be costed: rent with no amount, a share with no percentage,
     * or a direct operation carrying a landlord who is owed nothing.
     *
     * @return array{0:string,1:array}|null
     */
    protected function shapeError(array $d): ?array
    {
        if (! empty($d['effective_to']) && $d['effective_to'] < $d['effective_from']) {
            return ['The end date must be on or after the start date.', ['effective_to' => ['Before the start date.']]];
        }

        if ($d['deal_type'] === Agreement::DEAL_FIXED_RENT) {
            if (! isset($d['monthly_rent']) || (float) $d['monthly_rent'] <= 0) {
                return ['Enter a monthly rent greater than zero.', ['monthly_rent' => ['Required for fixed rent.']]];
            }
            if (empty($d['landlord_name'])) {
                return ['Enter the landlord or counterparty name.', ['landlord_name' => ['Required.']]];
            }
        }

        if ($d['deal_type'] === Agreement::DEAL_REVENUE_SHARE) {
            // Zero is legitimate — the landlord may take everything — so the
            // test is "provided", not "truthy".
            if (! isset($d['bookboll_share_percent'])) {
                return ['Enter Bookboll\'s share.', ['bookboll_share_percent' => ['Required for revenue share.']]];
            }
            if (empty($d['landlord_name'])) {
                return ['Enter the landlord or counterparty name.', ['landlord_name' => ['Required.']]];
            }
        }

        if ($d['change_type'] !== 'initial' && empty($d['change_reason'])) {
            return ['Give a reason for the change.', ['change_reason' => ['Required for a renewal or amendment.']]];
        }

        return null;
    }

    protected function map(Agreement $a, Venue $venue): array
    {
        return [
            'id'            => (string) $a->id,
            'version'       => $a->version,
            'deal_type'     => $a->deal_type,
            'currency'      => $a->currency,
            'landlord_name' => $a->landlord_name ?: '',
            'monthly_rent'  => $a->monthly_rent_minor === null
                ? null : Money::toMajor($a->monthly_rent_minor, $a->currency),
            'bookboll_share_percent' => $a->bookboll_share_percent === null
                ? null : (float) $a->bookboll_share_percent,
            // What the contract says — preserved even after a successor cuts
            // the term short.
            'effective_from' => $a->effective_from?->toDateString(),
            'effective_to'   => $a->effective_to?->toDateString(),
            // What it actually governed, which is what reporting must use.
            'active_until'   => $a->activeUntil(),
            'superseded_on'  => $a->superseded_on?->toDateString(),
            'change_type'    => $a->change_type,
            'change_reason'  => $a->change_reason ?: '',
            'supersedes_agreement_id' => $a->supersedes_agreement_id ? (string) $a->supersedes_agreement_id : null,
            'contract_document_id'    => $a->contract_document_id ? (string) $a->contract_document_id : null,
            'contract_name'  => $a->contract_document_id
                ? VenueContractDocument::whereKey($a->contract_document_id)->value('original_name')
                : null,
            'revenue_basis'  => $a->revenue_basis,
            'rent_proration' => $a->rent_proration,
            'notes'          => $a->notes ?: '',
            'created_at'     => $a->created_at?->toIso8601String(),
        ];
    }

    /** Today where the venue is, not where the server is. */
    protected function todayAtVenue(Venue $venue): string
    {
        $tz = $venue->timezone_override ?: config('app.timezone', 'UTC');

        try {
            return CarbonImmutable::now($tz)->toDateString();
        } catch (\Throwable $e) {
            return CarbonImmutable::now()->toDateString();
        }
    }

    /**
     * Currencies an agreement for this venue may be written in: the venue's
     * own, so rent cannot be agreed in a currency the venue never trades in.
     *
     * @return array<int, string>
     */
    protected function currencies(Venue $venue): array
    {
        $codes = [];

        if ($venue->currency_override) {
            $codes[] = strtoupper($venue->currency_override);
        }

        $countryId = app(BookingWalletService::class)->countryIdForVenue($venue);
        if ($countryId) {
            $code = \Nnjeim\World\Models\Country::with('currency')->find($countryId)?->currency?->code;
            if ($code) {
                $codes[] = strtoupper($code);
            }
        }

        return array_values(array_unique($codes));
    }

    protected function canManage($actor): bool
    {
        return AccessScope::isGlobal($actor) || $actor->hasAnyCapability(['manage_locations']);
    }

    /** @return array{0: ?Venue, 1: ?\App\Models\User, 2: mixed} */
    protected function resolve(Request $request, int $venueId): array
    {
        $actor = $request->user();

        if (! $actor || AccessScope::isProviderScoped($actor)) {
            return [null, null, sendError('You do not have access to operating agreements.', [], 403)];
        }

        $venue = Venue::find($venueId);
        if (! $venue) {
            return [null, null, sendError('Venue not found.', [], 404)];
        }

        // Agreements describe what Bookboll owes a landlord for a venue IT
        // operates. A provider-run venue has no such arrangement.
        if (($venue->operator_type ?: ($venue->owner_user_id ? 'provider' : 'bookboll')) !== 'bookboll') {
            return [null, null, sendError('This venue is not operated by Bookboll.', [], 422)];
        }

        return [$venue, $actor, null];
    }
}
