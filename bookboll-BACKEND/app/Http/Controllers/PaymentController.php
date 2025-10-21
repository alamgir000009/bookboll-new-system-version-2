<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(protected PaymentService $paymentService)
    {}

    public function index(Request $request)
    {
        return $this->paymentService->list($request);
    }

    public function totals()
    {
        return response()->json($this->paymentService->getDashboardTotals());
    }

    public function export(Request $request)
    {
        $filters = $request->query(); // e.g., ?method=cash&status=paid
        $data    = $this->paymentService->exportPaymentsCsv($filters);

        // Use Laravel Excel or simple CSV response
        $csv = collect($data)->prepend(array_keys($data[0] ?? []))->map(fn($row) => collect($row)->implode(','))->implode("\n");
        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="payments.csv"']);
    }

    public function downloadInvoice($id)
    {
        return $this->paymentService->downloadInvoice($id);
    }
}
