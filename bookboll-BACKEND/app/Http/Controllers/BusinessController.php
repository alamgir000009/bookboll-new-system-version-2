<?php

namespace App\Http\Controllers;

use App\Services\BusinessService;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function __construct(protected BusinessService $businessService)
    {}

    public function register(Request $request)
    {
        return $this->businessService->register($request);
    }

    public function usernameAvailable(Request $request)
    {
        return $this->businessService->usernameAvailable($request);
    }

    public function countries(Request $request)
    {
        return $this->businessService->countries($request);
    }

    public function updateBrand(Request $request)
    {
        return $this->businessService->updateBrand($request);
    }
}
