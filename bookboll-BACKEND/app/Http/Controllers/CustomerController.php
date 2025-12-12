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
}
