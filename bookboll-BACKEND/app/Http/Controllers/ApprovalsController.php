<?php

namespace App\Http\Controllers;

use App\Services\ApprovalsDashboardService;
use Illuminate\Http\Request;

class ApprovalsController extends Controller
{
    public function __construct(protected ApprovalsDashboardService $service)
    {}

    public function dashboard(Request $request)
    {
        return $this->service->bootstrap($request);
    }

    public function approve(Request $request, string $type, string $id)
    {
        return $this->service->approve($request, $type, $id);
    }

    public function requestChanges(Request $request, string $type, string $id)
    {
        return $this->service->requestChanges($request, $type, $id);
    }

    /** Suspend a provider's entire business from the marketplace. */
    public function suspendBusiness(string $ownerUserId)
    {
        return $this->service->suspendBusiness($ownerUserId);
    }

    /** Put a suspended business back on the marketplace. */
    public function reinstateBusiness(string $ownerUserId)
    {
        return $this->service->reinstateBusiness($ownerUserId);
    }

    public function bulkApprove(Request $request)
    {
        return $this->service->bulkApprove($request);
    }
}
