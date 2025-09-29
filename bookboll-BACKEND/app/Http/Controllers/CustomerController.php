<?php
namespace App\Http\Controllers;

use App\Services\CustomerService;
use Illuminate\Http\Request;

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

}
