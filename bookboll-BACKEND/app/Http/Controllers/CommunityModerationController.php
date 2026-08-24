<?php

namespace App\Http\Controllers;

use App\Services\CommunityModerationService;
use Illuminate\Http\Request;

class CommunityModerationController extends Controller
{
    public function __construct(protected CommunityModerationService $service)
    {}

    public function dashboard(Request $request)
    {
        return $this->service->dashboard($request);
    }

    public function flag(Request $request, string $id)
    {
        return $this->service->flag($request, $id);
    }

    public function remove(Request $request, string $id)
    {
        return $this->service->remove($request, $id);
    }

    public function restore(Request $request, string $id)
    {
        return $this->service->restore($request, $id);
    }
}
