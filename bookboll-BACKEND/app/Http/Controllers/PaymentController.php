<?php
namespace App\Http\Controllers;

use App\Exports\PaymentsExport;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentMethod as PaymentMethodModel;
use App\Models\StripeSession;
use App\Services\PaymentService;
use BookingPaymentMethod;
use Maatwebsite\Excel\Facades\Excel;
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
        $user = auth()->user();
        
        if (!$user->hasAnyRole(['super_admin', 'admin'])) {
            return sendError('Unauthorized. Only super admin or admin can export payments.', [], 403);
        }

        $payments = $this->paymentService->getPaymentsForExport($request);
        $filename = 'payments-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new PaymentsExport($payments), $filename);
    }

    public function downloadInvoice($id)
    {
        $user = auth()->user();
        
        if (!$user->hasAnyRole(['super_admin', 'admin'])) {
            return sendError('Unauthorized. Only super admin or admin can download payment invoices.', [], 403);
        }

        return $this->paymentService->downloadInvoice($id);
    }

    public function createPaymentIntent(Request $request)
    {
        $request->validate([
            'amount'                  => 'required|integer|min:100',
            'currency'                => 'required|string',
            'listing_id'              => 'required|integer',
            'promocode'               => 'nullable|string',
            'payment_method'          => 'required|string',
            'venue_id'                => 'required|integer',
            'saved_payment_method_id' => 'nullable|numeric|exists:payment_methods,id',
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

            $paymentIntentData = [
                'payment_method_types' => ['card'],
                'metadata'             => $metadata,
                'setup_future_usage'   => 'off_session',
                'amount'               => $request->amount,
                'customer'             => $user->stripe_id,
                'currency'             => $request->currency,
                'description'          => 'Bookboll Booking payment intent for user #' . $user->email,
            ];

            if ($request->saved_payment_method_id) {
                $savedPaymentMethod = PaymentMethodModel::where('id', $request->saved_payment_method_id)
                    ->where('user_id', $user->id)->first();

                if (! $savedPaymentMethod) {
                    return sendError('Payment method not found or does not belong to you');
                }

                if ($savedPaymentMethod->stripe_payment_method_id) {
                    try {
                        $stripePm = $user->findPaymentMethod($savedPaymentMethod->stripe_payment_method_id);
                        if ($stripePm) {
                            $paymentIntentData['payment_method'] = $savedPaymentMethod->stripe_payment_method_id;
                        } else {
                            Log::warning('Stripe payment method not found for saved payment method', [
                                'saved_payment_method_id'  => $request->saved_payment_method_id,
                                'stripe_payment_method_id' => $savedPaymentMethod->stripe_payment_method_id,
                                'user_id'                  => $user->id,
                            ]);
                            return sendError('Saved payment method is no longer available in Stripe. Please use a different payment method.', [], 404);
                        }
                    } catch (Exception $e) {
                        Log::error('Error retrieving Stripe payment method', [
                            'saved_payment_method_id'  => $request->saved_payment_method_id,
                            'stripe_payment_method_id' => $savedPaymentMethod->stripe_payment_method_id,
                            'user_id'                  => $user->id,
                            'error'                    => $e->getMessage(),
                        ]);
                        return sendError('Error retrieving saved payment method. Please use a different payment method.', [], 500);
                    }
                } else {

                    $stripePaymentMethods = $user->paymentMethods();

                    $matchingStripePaymentMethod = null;
                    foreach ($stripePaymentMethods as $stripePm) {
                        if ($stripePm->type === 'card' && $stripePm->card) {
                            $card = $stripePm->card;
                            if (
                                $card->last4 === $savedPaymentMethod->last_four &&
                                strtolower($card->brand) === strtolower($savedPaymentMethod->brand) &&
                                $card->exp_month === $savedPaymentMethod->exp_month &&
                                $card->exp_year === $savedPaymentMethod->exp_year
                            ) {
                                $matchingStripePaymentMethod = $stripePm;
                                $savedPaymentMethod->update(['stripe_payment_method_id' => $stripePm->id]);
                                break;
                            }
                        }
                    }

                    if ($matchingStripePaymentMethod) {
                        $paymentIntentData['payment_method'] = $matchingStripePaymentMethod->id;
                    } else {
                        Log::warning('Saved payment method found but no matching Stripe payment method', [
                            'saved_payment_method_id' => $request->saved_payment_method_id,
                            'user_id'                 => $user->id,
                        ]);
                        return sendError('Saved payment method not found in Stripe. Please use a different payment method.', [], 404);
                    }
                }
            }

            $payment = PaymentIntent::create($paymentIntentData);

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
        $user = auth()->user();
        
        if (!$user->hasAnyRole(['super_admin', 'admin'])) {
            return sendError('Unauthorized. Only super admin or admin can manage payments.', [], 403);
        }

        return $this->paymentService->generatePaymentLink($request);
    }

    public function confirmPayment(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->hasAnyRole(['super_admin', 'admin'])) {
            return sendError('Unauthorized. Only super admin or admin can manage payments.', [], 403);
        }

        return $this->paymentService->confirmPayment($request);
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
                // Load bookings for already processed session
                $mainBookingId   = $session->metadata['booking_id'] ?? null;
                $dueBookingsJson = $session->metadata['due_bookings'] ?? '[]';
                $dueIds          = json_decode($dueBookingsJson, true) ?? [];
                $bookingIds      = array_unique(array_filter(array_merge([$mainBookingId], $dueIds)));

                $bookings = Booking::with(['venue:id,name', 'listing:id,name'])
                    ->whereIn('id', $bookingIds)
                    ->get();

                return view('payment-success', compact('bookings'));
            }

            $stripeSession = $existingSession ?? StripeSession::create([
                'session_id' => $session_id,
                'processed'  => false,
            ]);

            if ($session->payment_status !== 'paid') {
                DB::rollBack();
                return view('payment-success', ['error' => 'Payment not completed. Please try again.']);
            }

            $paymentIntentId = $session->payment_intent;
            $intent          = PaymentIntent::retrieve($paymentIntentId);
            $chargeId        = $intent->latest_charge;

            if (! $chargeId) {
                DB::rollBack();
                Log::error('No charge ID found for payment intent', ['payment_intent_id' => $paymentIntentId]);
                return view('payment-success', ['error' => 'Payment verification failed: No charge found.']);
            }

            $mainBookingId   = $session->metadata['booking_id'] ?? null;
            $userId          = $session->metadata['user_id'] ?? null;
            $dueBookingsJson = $session->metadata['due_bookings'] ?? '[]';
            $dueIds          = json_decode($dueBookingsJson, true) ?? [];

            if (! $userId) {
                DB::rollBack();
                Log::error('No user ID found in session metadata', ['session_id' => $session_id]);
                return view('payment-success', ['error' => 'Payment verification failed: No user found.']);
            }

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
                return view('payment-success', ['error' => 'Payment verification failed: No bookings found.']);
            }

            foreach ($bookingIds as $bookingId) {
                $booking = Booking::find($bookingId);
                if (! $booking) {
                    Log::warning('Booking not found', ['booking_id' => $bookingId]);
                    continue;
                }

                $pendingPayments = $booking->payments()
                    ->where('status', PaymentStatus::PENDING)
                    ->whereIn('type', [PaymentType::REMAINING, PaymentType::FULL])
                    ->get();

                if ($pendingPayments->isNotEmpty()) {
                    foreach ($pendingPayments as $payment) {
                        $notes = $payment->notes ?? "Full payment for booking #{$booking->id}";
                        $notes = str_replace('pending', 'paid online via Stripe', $notes);

                        $payment->update([
                            'notes'             => $notes,
                            'charge_id'         => $chargeId,
                            'payment_intent_id' => $paymentIntentId,
                            'status'            => PaymentStatus::PAID,
                            'method'            => PaymentMethod::ONLINE,
                            'date'              => now()->format('Y-m-d'),
                        ]);
                        $booking->history()->create([
                            'user_id'     => $userId,
                            'action'      => 'payment_received',
                            'description' => "Payment #{$payment->id} marked as PAID via Stripe. Charge ID: {$chargeId}",
                        ]);
                    }
                }
            }

            Booking::whereIn('id', $bookingIds)->update([
                'full_payment_received' => true,
                'payment_status'        => BookingPaymentStatus::FULLY_PAID,
                'payment_method'        => BookingPaymentMethod::ONLINE_PAYMENT,
            ]);

            foreach ($bookingIds as $bookingId) {
                $booking = Booking::find($bookingId);
                if ($booking) {
                    autoConfirmBookingIfPaid($booking);
                    regenerateBookingInvoice($booking);
                }
            }

            $stripeSession->update(['processed' => true]);

            DB::commit();

            // Load bookings with relationships for the success page
            $bookings = Booking::with(['venue:id,name', 'listing:id,name'])
                ->whereIn('id', $bookingIds)
                ->get();

            return view('payment-success', compact('bookings'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Stripe success route error: ' . $e->getMessage(), ['session_id' => $session_id]);
            return view('payment-success', ['error' => 'Payment verification failed. Please contact support if this issue persists.']);
        }
    }

    public function cancelPayment()
    {
        echo 'Payment cancelled. Please try again.';
    }

    public function getPaymentMethods()
    {
        return $this->paymentService->customerPaymentMethods();
    }

    public function markBookingAsPaid(Request $request)
    {
        $user = auth()->user();
        
        if (!$user->hasAnyRole(['super_admin', 'admin'])) {
            return sendError('Unauthorized. Only super admin or admin can manage payments.', [], 403);
        }

        return $this->paymentService->markBookingAsPaid($request);
    }
}
