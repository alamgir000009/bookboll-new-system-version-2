<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Stripe;

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

    public function createPaymentIntent(Request $request)
    {
        $request->validate([
            'amount'   => 'required|numeric|min:1',
            'currency' => 'required|string',
        ]);

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $intent = PaymentIntent::create([
                'amount'   => $request->amount * 100,
                'currency' => $request->currency,
                'metadata' => [
                    'listing_id' => $request->listing_id,
                    'user_id'    => auth()->id(),
                ],
            ]);

            return sendSuccess("Client secret", [
                'clientSecret'    => $intent->client_secret,
                'paymentIntentId' => $intent->id,
            ]);

        } catch (Exception $e) {
            Log::error('Stripe Payment Intent Error: ' . $e->getMessage());
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }
}
