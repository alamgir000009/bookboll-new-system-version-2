<?php

namespace App\Services;

use App\Models\Venue;
use Illuminate\Http\Request;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

class LocationManagementService
{
    public function listCountries(Request $request)
    {
        $search           = $request->search ?? null;
        $recordsPerPage   = $request->records_per_page ?? 20;

        $query = Country::query()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('iso2', 'LIKE', "%{$search}%")
                        ->orWhere('iso3', 'LIKE', "%{$search}%")
                        ->orWhere('region', 'LIKE', "%{$search}%");
                });
            })
            ->withCount(['states', 'cities'])
            ->orderBy('name');

        $countries = $request->boolean('pagination', true)
            ? $query->paginate($recordsPerPage)
            : $query->get();

        return sendSuccess('Countries fetched successfully', compact('countries'));
    }

    public function showCountry(int $id)
    {
        $country = Country::query()
            ->withCount(['states', 'cities'])
            ->findOrFail($id);

        return sendSuccess('Country fetched successfully', compact('country'));
    }

    public function createCountry(Request $request)
    {
        $data = $request->validate([
            'iso2'       => ['required', 'string', 'size:2', 'unique:countries,iso2'],
            'name'       => ['required', 'string', 'max:255'],
            'iso3'       => ['required', 'string', 'size:3', 'unique:countries,iso3'],
            'phone_code' => ['required', 'string', 'max:5'],
            'region'     => ['required', 'string', 'max:255'],
            'subregion'  => ['required', 'string', 'max:255'],
            'status'     => ['nullable', 'integer', 'in:0,1'],
        ]);

        $data['iso2']   = strtoupper($data['iso2']);
        $data['iso3']   = strtoupper($data['iso3']);
        $data['status'] = $data['status'] ?? 1;

        $country = Country::create(
            $this->onlyWorldColumns('countries', $data)
        );

        return sendSuccess('Country created successfully', compact('country'));
    }

    public function updateCountry(Request $request, int $id)
    {
        $country = Country::findOrFail($id);

        $data = $request->validate([
            'iso2'       => ['required', 'string', 'size:2', 'unique:countries,iso2,' . $id],
            'name'       => ['required', 'string', 'max:255'],
            'iso3'       => ['required', 'string', 'size:3', 'unique:countries,iso3,' . $id],
            'phone_code' => ['required', 'string', 'max:5'],
            'region'     => ['required', 'string', 'max:255'],
            'subregion'  => ['required', 'string', 'max:255'],
            'status'     => ['nullable', 'integer', 'in:0,1'],
        ]);

        $data['iso2'] = strtoupper($data['iso2']);
        $data['iso3'] = strtoupper($data['iso3']);

        $country->update($this->onlyWorldColumns('countries', $data));

        return sendSuccess('Country updated successfully', compact('country'));
    }

    public function deleteCountry(int $id)
    {
        $country = Country::findOrFail($id);

        if (Venue::where('country_id', $country->id)->exists()) {
            return sendError('Cannot delete country while venues are assigned to it.', [], 422);
        }

        if ($country->states()->exists()) {
            return sendError('Cannot delete country while it has states. Delete states first.', [], 422);
        }

        $country->delete();

        return sendSuccess('Country deleted successfully');
    }

    public function listStates(Request $request)
    {
        $search         = $request->search ?? null;
        $countryId      = $request->country_id ?? null;
        $recordsPerPage = $request->records_per_page ?? 20;

        $query = State::with('country:id,name,iso2')
            ->withCount('cities')
            ->when($countryId, fn ($q) => $q->where('country_id', $countryId))
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('country_code', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name');

        $states = $request->boolean('pagination', true)
            ? $query->paginate($recordsPerPage)
            : $query->get();

        return sendSuccess('States fetched successfully', compact('states'));
    }

    public function showState(int $id)
    {
        $state = State::with('country:id,name,iso2')
            ->withCount('cities')
            ->findOrFail($id);

        return sendSuccess('State fetched successfully', compact('state'));
    }

    public function createState(Request $request)
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name'       => ['required', 'string', 'max:255'],
        ]);

        $country = Country::findOrFail($data['country_id']);

        $data['country_code'] = $country->iso2;

        $state = State::create(
            $this->onlyWorldColumns('states', $data)
        );

        $state->load('country:id,name,iso2');

        return sendSuccess('State created successfully', compact('state'));
    }

    public function updateState(Request $request, int $id)
    {
        $state = State::findOrFail($id);

        $data = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name'       => ['required', 'string', 'max:255'],
        ]);

        $country = Country::findOrFail($data['country_id']);

        $data['country_code'] = $country->iso2;

        $state->update($this->onlyWorldColumns('states', $data));
        $state->load('country:id,name,iso2');

        return sendSuccess('State updated successfully', compact('state'));
    }

    public function deleteState(int $id)
    {
        $state = State::findOrFail($id);

        if (Venue::where('state_id', $state->id)->exists()) {
            return sendError('Cannot delete state while venues are assigned to it.', [], 422);
        }

        if ($state->cities()->exists()) {
            return sendError('Cannot delete state while it has cities. Delete cities first.', [], 422);
        }

        $state->delete();

        return sendSuccess('State deleted successfully');
    }

    public function listCities(Request $request)
    {
        $search         = $request->search ?? null;
        $countryId      = $request->country_id ?? null;
        $stateId        = $request->state_id ?? null;
        $recordsPerPage = $request->records_per_page ?? 20;

        $query = City::query()
            ->with([
                'country:id,name,iso2',
                'state:id,name,country_id',
            ])
            ->when($countryId, fn ($q) => $q->where('country_id', $countryId))
            ->when($stateId, fn ($q) => $q->where('state_id', $stateId))
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('country_code', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name');

        $cities = $request->boolean('pagination', true)
            ? $query->paginate($recordsPerPage)
            : $query->get();

        return sendSuccess('Cities fetched successfully', compact('cities'));
    }

    public function showCity(int $id)
    {
        $city = City::query()
            ->with([
                'country:id,name,iso2',
                'state:id,name,country_id',
            ])
            ->findOrFail($id);

        return sendSuccess('City fetched successfully', compact('city'));
    }

    public function createCity(Request $request)
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'state_id'   => ['required', 'integer', 'exists:states,id'],
            'name'       => ['required', 'string', 'max:255'],
        ]);

        $country = Country::findOrFail($data['country_id']);
        $state   = State::findOrFail($data['state_id']);

        if ((int) $state->country_id !== (int) $country->id) {
            return sendError('The selected state does not belong to the selected country.', [], 422);
        }

        $data['country_code'] = $country->iso2;

        $city = City::create(
            $this->onlyWorldColumns('cities', $data)
        );
        $city->load(['country:id,name,iso2', 'state:id,name,country_id']);

        return sendSuccess('City created successfully', compact('city'));
    }

    public function updateCity(Request $request, int $id)
    {
        $city = City::findOrFail($id);

        $data = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'state_id'   => ['required', 'integer', 'exists:states,id'],
            'name'       => ['required', 'string', 'max:255'],
        ]);

        $country = Country::findOrFail($data['country_id']);
        $state   = State::findOrFail($data['state_id']);

        if ((int) $state->country_id !== (int) $country->id) {
            return sendError('The selected state does not belong to the selected country.', [], 422);
        }

        $data['country_code'] = $country->iso2;

        $city->update($this->onlyWorldColumns('cities', $data));
        $city->load(['country:id,name,iso2', 'state:id,name,country_id']);

        return sendSuccess('City updated successfully', compact('city'));
    }

    public function deleteCity(int $id)
    {
        $city = City::findOrFail($id);

        if (Venue::where('city_id', $city->id)->exists()) {
            return sendError('Cannot delete city while venues are assigned to it.', [], 422);
        }

        $city->delete();

        return sendSuccess('City deleted successfully');
    }

    protected function worldColumns(string $entity): array
    {
        $baseColumns = match ($entity) {
            'countries' => ['iso2', 'name', 'status'],
            'states'    => ['country_id', 'name'],
            'cities'    => ['country_id', 'state_id', 'name'],
            default     => [],
        };

        $optionalFields = config("world.migrations.{$entity}.optional_fields", []);
        $enabledColumns = collect($optionalFields)
            ->filter(fn (array $field) => ($field['required'] ?? false) === true)
            ->keys()
            ->all();

        return array_merge($baseColumns, $enabledColumns);
    }

    protected function onlyWorldColumns(string $entity, array $data): array
    {
        return array_intersect_key($data, array_flip($this->worldColumns($entity)));
    }
}
