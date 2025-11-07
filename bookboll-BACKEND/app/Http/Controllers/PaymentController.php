<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\StripeSession;
use App\Services\PaymentService;
use BookingPaymentMethod;
use BookingPaymentStatus;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PaymentMethod;
use PaymentStatus;
use PaymentType;
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

    public function generatePaymentLink(Request $request)
    {
        return $this->paymentService->generatePaymentLink($request);
    }

    public function paymentSuccess($session_id)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            DB::beginTransaction();

            $session = \Stripe\Checkout\Session::retrieve($session_id);
            Log::info('Before main booking update');

            $existingSession = StripeSession::where('session_id', $session_id)->first();
            if ($existingSession && $existingSession->processed) {
                return response('This payment session has already been processed.', 200);
            }

            $stripeSession = $existingSession ?? StripeSession::create([
                'session_id' => $session_id,
                'processed'  => false,
            ]);

            if ($session->payment_status !== 'paid') {
                DB::rollBack();
                return response('Payment not completed.', 400);
            }

            $paymentIntentId = $session->payment_intent;
            $intent          = PaymentIntent::retrieve($paymentIntentId);
            $chargeId        = $intent->latest_charge;

            if (! $chargeId) {
                DB::rollBack();
                Log::error('No charge ID found for payment intent', ['payment_intent_id' => $paymentIntentId]);
                return response('Payment verification failed: No charge found.', 400);
            }

            $mainBookingId   = $session->metadata['booking_id'] ?? null;
            $dueBookingsJson = $session->metadata['due_bookings'] ?? '[]';
            $dueIds          = json_decode($dueBookingsJson, true) ?? [];

            $bookingIds = [];
            if ($mainBookingId) {
                $bookingIds[] = $mainBookingId;
            }
            if (! empty($dueIds)) {
                $bookingIds = array_merge($bookingIds, $dueIds);
            }
            $bookingIds = array_unique(array_filter($bookingIds));

            if (empty($bookingIds)) {
                DB::rollBack();
                Log::error('No booking IDs found in session metadata', ['session_id' => $session_id]);
                return response('Payment verification failed: No bookings found.', 400);
            }

            foreach ($bookingIds as $bookingId) {
                $booking = Booking::find($bookingId);
                if (! $booking) {
                    Log::warning('Booking not found', ['booking_id' => $bookingId]);
                    continue;
                }

                $userId     = $booking->user_id;
                $totalPrice = $booking->total_price;

                $pendingPayment = $booking->payments()
                    ->where('status', PaymentStatus::PENDING)
                    ->whereIn('type', [PaymentType::REMAINING, PaymentType::FULL])
                    ->first();

                $notes = "Full payment for booking #{$booking->id}";

                if ($pendingPayment) {
                    $type   = $pendingPayment->type;
                    $amount = $pendingPayment->amount;
                    $notes  = str_replace('pending', 'paid online via Stripe', $pendingPayment->notes);
                    $pendingPayment->update([
                        'notes'             => $notes,
                        'charge_id'         => $chargeId,
                        'payment_intent_id' => $paymentIntentId,
                        'status'            => PaymentStatus::PAID,
                        'method'            => PaymentMethod::ONLINE,
                        'date'              => now()->format('Y-m-d'),
                    ]);
                }
            }

            Booking::whereIn('id', $bookingIds)->update([
                'full_payment_received' => true,
                'payment_status'        => BookingPaymentStatus::FULLY_PAID,
                'payment_method'        => BookingPaymentMethod::ONLINE_PAYMENT,
            ]);

            $stripeSession->update(['processed' => true]);

            DB::commit();

            return response('Payment successful! Booking updated.', 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Stripe success route error: ' . $e->getMessage(), ['session_id' => $session_id]);
            return response('Payment verification failed');
        }
    }

    public function cancelPayment()
    {
        echo 'Payment cancelled. Please try again.';
    }
}
