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

    public function adminIndex(Request $request)
    {
        return $this->businessService->adminIndex($request);
    }

    public function teamIndex(Request $request)
    {
        return $this->businessService->teamIndex($request);
    }

    public function teamStore(Request $request)
    {
        return $this->businessService->teamStore($request);
    }

    public function teamUpdate(Request $request, $user)
    {
        return $this->businessService->teamUpdate($request, $user);
    }

    public function teamDestroy(Request $request, $user)
    {
        return $this->businessService->teamDestroy($request, $user);
    }
}
