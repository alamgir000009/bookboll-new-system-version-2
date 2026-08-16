<?php

namespace App\Services;

use App\Models\CommissionRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
        ];
    }
}
