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
            'amount'         => 'required|integer|min:100',
            'currency'       => 'required|string',
            'listing_id'     => 'required|integer',
            'promocode'      => 'nullable|string',
            'payment_method' => 'required|string',
            'venue_id'       => 'required|integer',
        ]);

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $user = auth()->user();

            $user->createOrGetStripeCustomer();

            if (! $user->stripe_id) {
                Log::error('No Stripe Customer ID for user', ['user_id' => $user->id]);
                return sendError('Customer setup failed', [], 500);
            }

            $metadata = [
                'user_id'        => $user->id,
                'venue_id'       => $request->venue_id,
                'promocode'      => $request->promocode,
                'listing_id'     => $request->listing_id,
                'payment_method' => $request->payment_method,
            ];

            $payment = PaymentIntent::create([
                'amount'               => $request->amount,
                'currency'             => $request->currency,
                'customer'             => $user->stripe_id,
                'payment_method_types' => ['card'],
                'metadata'             => $metadata,
                'description'          => 'Booking payment intent',
                'setup_future_usage'   => 'off_session',
            ]);

            return sendSuccess("Client secret created successfully", [
                'client_secret'     => $payment->client_secret,
                'payment_intent_id' => $payment->id,
            ]);

        } catch (Exception $e) {
            Log::error('Stripe Payment Intent Error: ' . $e->getMessage(), [
                'user_id'      => auth()->id(),
                'request_data' => $request->all(),
                'customer_id'  => $user->stripe_id ?? 'null',
            ]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }
}
