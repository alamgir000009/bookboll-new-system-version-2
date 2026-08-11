<?php

namespace App\Http\Controllers;

use App\Services\LocationService;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function __construct(protected LocationService $locationService) {}

    public function countries(Request $request)
    {
        return $this->locationService->countries($request);
    }

    public function states(Request $request)
    {
        return $this->locationService->states($request);
    }

    public function cities(Request $request)
    {
        return $this->locationService->cities($request);
    }
}
