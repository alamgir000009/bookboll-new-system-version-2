<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\State;
use Nnjeim\World\World;

class LocationService
{
    public function countries(Request $request)
    {
        $search = $request->query('search');

        $action = World::countries([
            'fields' => 'id,name,iso2',
            'search' => $search,
        ]);

        if (! $action->success) {
            return sendSuccess('Countries fetched successfully', ['countries' => []]);
        }

        $countries = $action->data->map(fn ($country) => [
            'id'   => data_get($country, 'id'),
            'name' => data_get($country, 'name'),
            'iso2' => data_get($country, 'iso2'),
        ])->values();

        return sendSuccess('Countries fetched successfully', compact('countries'));
    }

    public function states(Request $request)
    {
        $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
        ]);

        $action = World::states([
            'fields'   => 'id,name,country_id',
            'filters'  => ['country_id' => (int) $request->query('country_id')],
            'search'   => $request->query('search'),
        ]);

        if (! $action->success) {
            return sendSuccess('States fetched successfully', ['states' => []]);
        }

        $states = $action->data->map(fn ($state) => [
            'id'         => data_get($state, 'id'),
            'name'       => data_get($state, 'name'),
            'country_id' => data_get($state, 'country_id'),
        ])->values();

        return sendSuccess('States fetched successfully', compact('states'));
    }

    public function cities(Request $request)
    {
        $request->validate([
            'state_id'   => ['nullable', 'integer', 'exists:states,id', 'required_without:country_id'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id', 'required_without:state_id'],
        ]);

        $filters = [];

        if ($request->filled('state_id')) {
            $filters['state_id'] = (int) $request->query('state_id');
        } elseif ($request->filled('country_id')) {
            $filters['country_id'] = (int) $request->query('country_id');
        }

        $action = World::cities([
            'fields'  => 'id,name,country_id,state_id',
            'filters' => $filters,
            'search'  => $request->query('search'),
        ]);

        if (! $action->success) {
            return sendSuccess('Cities fetched successfully', ['cities' => []]);
        }

        $cities = $action->data->map(fn ($city) => [
            'id'         => data_get($city, 'id'),
            'name'       => data_get($city, 'name'),
            'country_id' => data_get($city, 'country_id'),
            'state_id'   => data_get($city, 'state_id'),
        ])->values();

        return sendSuccess('Cities fetched successfully', compact('cities'));
    }

    public function validateVenueLocation(array $data): void
    {
        $countryId = (int) ($data['country_id'] ?? 0);
        $stateId   = isset($data['state_id']) ? (int) $data['state_id'] : null;
        $cityId    = (int) ($data['city_id'] ?? 0);

        if ($stateId) {
            $state = State::find($stateId);

            if (! $state || (int) $state->country_id !== $countryId) {
                throw ValidationException::withMessages([
                    'state_id' => ['The selected state does not belong to the selected country.'],
                ]);
            }
        }

        $city = City::find($cityId);

        if (! $city || (int) $city->country_id !== $countryId) {
            throw ValidationException::withMessages([
                'city_id' => ['The selected city does not belong to the selected country.'],
            ]);
        }

        if ($stateId && (int) $city->state_id !== $stateId) {
            throw ValidationException::withMessages([
                'city_id' => ['The selected city does not belong to the selected state.'],
            ]);
        }

        if (! $stateId && $city->state_id) {
            throw ValidationException::withMessages([
                'state_id' => ['A state is required for the selected city.'],
            ]);
        }
    }
}
