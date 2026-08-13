<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

class LocationService
{
    public function countries(Request $request)
    {
        $search = $request->query('search');

        $countries = Country::select('id', 'name', 'iso2')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('iso2', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get()
            ->map(fn($country) => [
                'id'   => $country->id,
                'name' => $country->name,
                'iso2' => $country->iso2,
            ])
            ->values();

        return sendSuccess('Countries fetched successfully', compact('countries'));
    }

    public function states(Request $request)
    {
        $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
        ]);

        $search = $request->query('search');

        $states = State::select('id', 'name', 'country_id')
            ->where('country_id', (int) $request->query('country_id'))
            ->when($search, fn($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->get()
            ->map(fn($state) => [
                'id'         => $state->id,
                'name'       => $state->name,
                'country_id' => $state->country_id,
            ])
            ->values();

        return sendSuccess('States fetched successfully', compact('states'));
    }

    public function cities(Request $request)
    {
        $request->validate([
            'state_id'   => ['nullable', 'integer', 'exists:states,id', 'required_without:country_id'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id', 'required_without:state_id'],
        ]);

        $search = $request->query('search');

        $cities = City::select('id', 'name', 'country_id', 'state_id')
            ->when($request->filled('state_id'), fn($query) => $query->where('state_id', (int) $request->query('state_id')))
            ->when($request->filled('country_id') && ! $request->filled('state_id'), fn($query) => $query->where('country_id', (int) $request->query('country_id')))
            ->when($search, fn($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->get()
            ->map(fn($city) => [
                'id'         => $city->id,
                'name'       => $city->name,
                'country_id' => $city->country_id,
                'state_id'   => $city->state_id,
            ])
            ->values();

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
