<?php
namespace App\Http\Controllers;

use App\Services\AuditLogService;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(protected AuditLogService $auditLogService)
    {}

    public function index(Request $request)
    {
        return $this->auditLogService->getLogs($request);
    }

    public function store(Request $request)
    {
        return $this->auditLogService->store($request);
    }

    public function export(Request $request)
    {
        return $this->auditLogService->exportCsv($request);
    }

    public function stats()
    {
        return sendSuccess('Log Stats fetched successfully', ['stats' => $this->auditLogService->getStats()]);
    }
}
