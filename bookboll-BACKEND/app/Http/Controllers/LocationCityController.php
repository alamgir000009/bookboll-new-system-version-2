<?php

namespace App\Http\Controllers;

use App\Services\LocationManagementService;
use Illuminate\Http\Request;

class LocationCityController extends Controller
{
    public function __construct(protected LocationManagementService $locationManagementService) {}

    public function index(Request $request)
    {
        return $this->locationManagementService->listCities($request);
    }

    public function store(Request $request)
    {
        return $this->locationManagementService->createCity($request);
    }

    public function show(int $city)
    {
        return $this->locationManagementService->showCity($city);
    }

    public function update(Request $request, int $city)
    {
        return $this->locationManagementService->updateCity($request, $city);
    }

    public function destroy(int $city)
    {
        return $this->locationManagementService->deleteCity($city);
    }
}
