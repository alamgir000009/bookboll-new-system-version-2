<?php

namespace App\Http\Controllers;

use App\Services\LocationManagementService;
use Illuminate\Http\Request;

class LocationCountryController extends Controller
{
    public function __construct(protected LocationManagementService $locationManagementService) {}

    public function index(Request $request)
    {
        return $this->locationManagementService->listCountries($request);
    }

    public function store(Request $request)
    {
        return $this->locationManagementService->createCountry($request);
    }

    public function show(int $country)
    {
        return $this->locationManagementService->showCountry($country);
    }

    public function update(Request $request, int $country)
    {
        return $this->locationManagementService->updateCountry($request, $country);
    }

    public function destroy(int $country)
    {
        return $this->locationManagementService->deleteCountry($country);
    }
}
