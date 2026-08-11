<?php

namespace App\Http\Controllers;

use App\Services\LocationManagementService;
use Illuminate\Http\Request;

class LocationStateController extends Controller
{
    public function __construct(protected LocationManagementService $locationManagementService) {}

    public function index(Request $request)
    {
        return $this->locationManagementService->listStates($request);
    }

    public function store(Request $request)
    {
        return $this->locationManagementService->createState($request);
    }

    public function show(int $state)
    {
        return $this->locationManagementService->showState($state);
    }

    public function update(Request $request, int $state)
    {
        return $this->locationManagementService->updateState($request, $state);
    }

    public function destroy(int $state)
    {
        return $this->locationManagementService->deleteState($state);
    }
}
