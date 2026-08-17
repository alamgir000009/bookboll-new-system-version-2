<?php
namespace App\Http\Controllers;

use App\Exports\CustomersExport;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CustomerController extends Controller
{
    public function __construct(protected CustomerService $customerService)
    {}

    public function index(Request $request)
    {
        return $this->customerService->list($request);
    }

    public function dashboard(Request $request)
    {
        return $this->customerService->dashboard($request);
    }

    public function crm($id)
    {
        return $this->customerService->crm($id);
    }

    public function store(Request $request)
    {
        return $this->customerService->storeDashboard($request);
    }

    public function update(Request $request, $id)
    {
        return $this->customerService->updateDashboard($request, $id);
    }

    public function replaceLoyalty(Request $request, $id)
    {
        return $this->customerService->replaceLoyalty($request, $id);
    }

    public function upsertLoyalty(Request $request, $id)
    {
        return $this->customerService->upsertLoyalty($request, $id);
    }

    public function deleteLoyalty($id, $countryId)
    {
        return $this->customerService->deleteLoyalty($id, $countryId);
    }

    public function listOptimized(Request $request)
    {
        return $this->customerService->listOptimized($request);
    }

    public function updateTags(Request $request, $id)
    {
        return $this->customerService->updateTags($request, $id);
    }

    public function updateStatus(Request $request, $id)
    {
        return $this->customerService->updateStatus($request, $id);
    }

    public function createCustomer(Request $request)
    {
        return $this->customerService->createCustomer($request);
    }

    public function export(Request $request)
    {
        $customers = $this->customerService->getCustomersForExport($request);
        $filename = 'customers-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new CustomersExport($customers), $filename);
    }

    public function adminProfile($id)
    {
        return $this->customerService->getAdminProfile((string) $id);
    }

    public function adminBookings(Request $request, $id)
    {
        return $this->customerService->getAdminBookings($request, (string) $id);
    }

    public function destroy($id)
    {
        return $this->customerService->delete($id);
    }
}
