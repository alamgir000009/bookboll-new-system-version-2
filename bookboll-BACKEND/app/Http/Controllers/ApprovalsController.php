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

    public function bulkApprove(Request $request)
    {
        return $this->service->bulkApprove($request);
    }
}
