<?php

namespace App\Services;

use App\Models\CommissionRate;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Admin Countries & Cities page — World package + commission rates.
 */
class GeographyService
{
    public const LISTING_TYPES = ['facility', 'program', 'event'];

    public function countries(Request $request)
    {
        $search = $request->query('search');
        $status = $request->query('status'); // active|inactive|null
        $recordsPerPage = (int) ($request->input('records_per_page') ?? $request->query('records_per_page', 25));
        if ($recordsPerPage < 1) {
            $recordsPerPage = 25;
        }
        if ($recordsPerPage > 250) {
            $recordsPerPage = 250;
        }

        $query = Country::withCount('cities')
            ->with('currency')
            ->orderBy('name');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('iso2', 'LIKE', "%{$search}%")
                    ->orWhere('iso3', 'LIKE', "%{$search}%")
                    ->orWhereHas('cities', function ($cq) use ($search) {
                        $cq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($status === 'active') {
            $query->where('status', 1);
        } elseif ($status === 'inactive') {
            $query->where('status', 0);
        }

        $actor = $request->user();
        if ($actor && $actor->hasRole('country_admin') && ! $actor->hasRole('super_admin')) {
            $allowed = collect($actor->assigned_country_ids ?? [])->filter()->map(fn($id) => (int) $id)->all();
            if ($actor->country_id) {
                $allowed[] = (int) $actor->country_id;
            }
            $allowed = array_values(array_unique($allowed));
            if ($allowed) {
                $query->whereIn('id', $allowed);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $paginator = $query->paginate($recordsPerPage);
        $countries = collect($paginator->items())->map(fn(Country $c) => $this->mapCountry($c))->values();

        $countryIds = $countries->pluck('id')->all();
        $rates = $countryIds
            ? CommissionRate::whereIn('country_id', $countryIds)->get()
            : collect();

        $commissionRates = [];
        foreach ($rates as $r) {
            $cid = (int) $r->country_id;
            if (! isset($commissionRates[$cid])) {
                $commissionRates[$cid] = [
                    'facility' => 15.0,
                    'program'  => 15.0,
                    'event'    => 10.0,
                ];
            }
            $commissionRates[$cid][$r->listing_type] = (float) $r->rate_percent;
        }

        return sendSuccess('Countries fetched successfully', [
            'countries'        => $countries,
            'commission_rates' => $commissionRates,
            'pagination'       => [
                'current_page'     => $paginator->currentPage(),
                'last_page'        => $paginator->lastPage(),
                'records_per_page' => $paginator->perPage(),
                'total'            => $paginator->total(),
                'from'             => $paginator->firstItem(),
                'to'               => $paginator->lastItem(),
            ],
        ]);
    }

    public function cities(Request $request)
    {
        $data = $request->validate([
            'country_id' => 'required|integer|exists:countries,id',
            'search'     => 'nullable|string|max:100',
        ]);

        $query = City::where('country_id', $data['country_id'])
            ->orderBy('name');

        if (! empty($data['search'])) {
            $query->where('name', 'LIKE', '%' . $data['search'] . '%');
        }

        $cities = $query->get()->map(fn(City $c) => $this->mapCity($c));

        return sendSuccess('Cities fetched successfully', compact('cities'));
    }

    public function updateCountryStatus(Request $request, int $id)
    {
        $country = Country::findOrFail($id);

        $data = $request->validate([
            'status' => 'required|in:active,inactive,0,1',
        ]);

        $status = in_array($data['status'], ['active', '1', 1], true) ? 1 : 0;
        $country->status = $status;
        $country->save();

        return sendSuccess('Country status updated', [
            'country' => $this->mapCountry($country->fresh()->loadCount('cities')),
        ]);
    }

    public function updateCityStatus(Request $request, int $id)
    {
        $city = City::findOrFail($id);

        $data = $request->validate([
            'status' => 'required|in:active,inactive,0,1',
        ]);

        $status = in_array($data['status'], ['active', '1', 1], true) ? 1 : 0;
        if (! \Schema::hasColumn('cities', 'status')) {
            return sendError('City status column is missing. Run migrations.', [], 500);
        }
        $city->status = $status;
        $city->save();

        return sendSuccess('City status updated', [
            'city' => $this->mapCity($city->fresh()),
        ]);
    }

    public function bulkUpdateCityStatus(Request $request)
    {
        $data = $request->validate([
            'country_id' => 'required|integer|exists:countries,id',
            'status'     => 'required|in:active,inactive,0,1',
            'city_ids'   => 'nullable|array',
            'city_ids.*' => 'integer',
        ]);

        $status = in_array($data['status'], ['active', '1', 1], true) ? 1 : 0;

        $query = City::where('country_id', $data['country_id']);
        if (! empty($data['city_ids'])) {
            $query->whereIn('id', $data['city_ids']);
        }
        $updated = $query->update(['status' => $status]);

        return sendSuccess('Cities updated', ['updated' => $updated]);
    }

    public function upsertCommission(Request $request)
    {
        $data = $request->validate([
            'country_id'   => 'required|integer|exists:countries,id',
            'listing_type' => 'required|string|in:' . implode(',', self::LISTING_TYPES),
            'rate_percent' => 'required|numeric|min:0|max:100',
        ]);

        $rate = CommissionRate::updateOrCreate(
            [
                'country_id'   => $data['country_id'],
                'listing_type' => $data['listing_type'],
            ],
            [
                'rate_percent' => $data['rate_percent'],
            ]
        );

        return sendSuccess('Commission rate saved', ['commission_rate' => $rate]);
    }

    /** Every saved per-country program, for the Countries page summary cards. */
    public function loyaltyPrograms()
    {
        $programs = LoyaltyProgram::query()->orderBy('country_id')->get();

        // Each country's tier ladder rides along, in the shape the dashboard
        // renders (threshold / sort_order). Rows with a null program_id are the
        // legacy global defaults and are offered to every country.
        $tiers = LoyaltyTier::query()
            ->orderBy('order')
            ->get()
            ->map(fn (LoyaltyTier $t) => $t->toDashboardRow())
            ->values();

        return sendSuccess('Loyalty programs fetched', [
            'loyalty_programs' => $programs,
            'loyalty_tiers'    => $tiers,
            'defaults'         => LoyaltyProgram::defaults(),
        ]);
    }

    public function upsertLoyaltyProgram(Request $request)
    {
        $data = $request->validate([
            'country_id'     => 'required|integer|exists:countries,id',
            'name'           => 'required|string|max:100',
            'earn_rate'      => 'required|numeric|min:0',
            'redeem_rate'    => 'required|integer|min:1',
            'min_redeem'     => 'required|integer|min:0',
            'max_redeem_pct' => 'required|integer|min:0|max:100',
            'expiry_months'  => 'required|integer|min:0|max:600',
            'is_active'      => 'required|boolean',
            // The tier ladder for this country. Absent = leave the tiers alone;
            // present (even empty) = replace them, matching the Supabase build,
            // which deleted the program's tiers and re-inserted what was on screen.
            'tiers'              => 'sometimes|array',
            // Blank rows are the editor's empty placeholders; replaceTiers drops
            // them. Rejecting the whole save over one would lose the other edits.
            'tiers.*.name'       => 'nullable|string|max:60',
            'tiers.*.threshold'  => 'nullable|numeric|min:0',
            'tiers.*.multiplier' => 'nullable|numeric|min:0',
            'tiers.*.color'      => 'nullable|string|max:32',
            'tiers.*.bg_color'   => 'nullable|string|max:32',
        ]);

        // redeem_value is standardised at 1 currency unit per step for every currency —
        // the only tunable knob is redeem_rate ("points for 1 unit off").
        $program = LoyaltyProgram::updateOrCreate(
            [
                'country_id' => $data['country_id'],
            ],
            [
                'name'           => $data['name'],
                'earn_rate'      => $data['earn_rate'],
                'redeem_rate'    => $data['redeem_rate'],
                'redeem_value'   => 1,
                'min_redeem'     => $data['min_redeem'],
                'max_redeem_pct' => $data['max_redeem_pct'],
                'expiry_months'  => $data['expiry_months'],
                'is_active'      => $data['is_active'],
            ]
        );

        if ($request->has('tiers')) {
            $this->replaceTiers($program, $data['tiers'] ?? []);
        }

        return sendSuccess('Loyalty program saved', [
            'loyalty_program' => $program,
            'loyalty_tiers'   => $program->tiers()->orderBy('order')->get()
                ->map(fn (LoyaltyTier $t) => $t->toDashboardRow())->values(),
        ]);
    }

    /**
     * Replace a program's tiers with exactly what was submitted.
     *
     * Done inside a transaction: a half-written ladder would silently re-grade
     * every customer in the country.
     */
    protected function replaceTiers(LoyaltyProgram $program, array $rows): void
    {
        DB::transaction(function () use ($program, $rows) {
            LoyaltyTier::where('program_id', $program->id)->delete();

            $order = 0;
            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;   // blank rows are the editor's empty placeholders
                }

                LoyaltyTier::create([
                    'program_id' => $program->id,
                    'name'       => $name,
                    'slug'       => Str::slug($name.'-'.$program->id.'-'.$order) ?: 'tier-'.$program->id.'-'.$order,
                    'color'      => $row['color'] ?? '#92400e',
                    'bg_color'   => $row['bg_color'] ?? '#fef3c7',
                    'min_points' => (int) ($row['threshold'] ?? 0),
                    'multiplier' => (float) ($row['multiplier'] ?? 1),
                    'order'      => $order,
                    'is_active'  => true,
                ]);
                $order++;
            }
        });
    }

    protected function mapCountry(Country $c): array
    {
        $status = (int) ($c->status ?? 1) === 1 ? 'active' : 'inactive';
        $currency = $c->relationLoaded('currency') ? $c->currency : null;

        return [
            'id'              => $c->id,
            'name'            => $c->name,
            'iso_code'        => $c->iso2,
            'iso2'            => $c->iso2,
            'iso3'            => $c->iso3 ?? null,
            'phone_code'      => $c->phone_code ?? null,
            'region'          => $c->region ?? null,
            'subregion'       => $c->subregion ?? null,
            'currency_code'   => $currency?->code,
            'currency_symbol' => $currency?->symbol,
            'timezone'        => null,
            'status'          => $status,
            'cities_count'    => (int) ($c->cities_count ?? 0),
        ];
    }

    protected function mapCity(City $c): array
    {
        $raw = Schema::hasColumn('cities', 'status') ? (int) ($c->status ?? 1) : 1;

        return [
            'id'         => $c->id,
            'country_id' => $c->country_id,
            'state_id'   => $c->state_id,
            'name'       => $c->name,
            'timezone'   => null,
            'status'     => $raw === 1 ? 'active' : 'inactive',
            // Per-city service modules. NULL reads as ON, matching the dashboard's
            // `module_* === false` test and the original Supabase default.
            'module_listings'    => $c->module_listings !== 0 && $c->module_listings !== false,
            'module_classes'     => $c->module_classes !== 0 && $c->module_classes !== false,
            'module_matchmaking' => $c->module_matchmaking !== 0 && $c->module_matchmaking !== false,
        ];
    }

    /**
     * Turn one service module on or off for a city.
     *
     * Disabling a module stops providers creating that kind of thing in the city;
     * anything already published is left alone, exactly as the Supabase build did.
     */
    public function updateCityModule(Request $request, int $id)
    {
        $data = $request->validate([
            'module'  => 'required|in:module_listings,module_classes,module_matchmaking',
            'enabled' => 'required|boolean',
        ]);

        $city = City::findOrFail($id);
        $city->{$data['module']} = (bool) $data['enabled'];
        $city->save();

        return sendSuccess('City module updated', ['city' => $this->mapCity($city->fresh())]);
    }
}
