<?php

namespace App\Http\Controllers;

use App\Services\ClassMembershipDashboardService;
use Illuminate\Http\Request;

class ClassMembershipController extends Controller
{
    public function __construct(protected ClassMembershipDashboardService $service)
    {}

    public function dashboard(Request $request)
    {
        return $this->service->bootstrap($request);
    }

    public function show($id)
    {
        return $this->service->show($id);
    }

    public function store(Request $request)
    {
        return $this->service->store($request);
    }

    public function payments($id)
    {
        return $this->service->payments($id);
    }

    public function charge(Request $request, $id)
    {
        return $this->service->charge($request, $id);
    }

    public function retryCharge(Request $request, $id)
    {
        return $this->service->retryCharge($request, $id);
    }

    public function cancel(Request $request, $id)
    {
        return $this->service->cancel($request, $id);
    }

    public function bulkCancel(Request $request)
    {
        return $this->service->bulkCancel($request);
    }

    public function pause(Request $request, $id)
    {
        return $this->service->pause($request, $id);
    }

    public function resume(Request $request, $id)
    {
        return $this->service->resume($request, $id);
    }

    public function markPastDue(Request $request, $id)
    {
        return $this->service->markPastDue($request, $id);
    }

    public function retentionMonthOff(Request $request, $id)
    {
        return $this->service->retentionMonthOff($request, $id);
    }

    public function winback(Request $request, $id)
    {
        return $this->service->winback($request, $id);
    }

    /** Create a membership programme (approval staging decided server-side). */
    public function storeProgram(Request $request)
    {
        return app(\App\Services\ProgramSaveService::class)->store($request);
    }

    /** Edit a membership programme. */
    public function updateProgram(Request $request, $id)
    {
        return app(\App\Services\ProgramSaveService::class)->update($request, $id);
    }

    /** Activate / archive, or pause / resume a programme. */
    public function setProgramState(Request $request, $id)
    {
        return app(\App\Services\ProgramSaveService::class)->setState($request, $id);
    }

    public function destroyProgram($id)
    {
        return $this->service->destroyProgram($id);
    }
}
