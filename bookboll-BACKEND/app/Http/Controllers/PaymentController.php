<?php
namespace App\Http\Controllers;

use App\Exports\PaymentsExport;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentMethod as PaymentMethodModel;
use App\Services\PaymentService;
use App\Services\StripeService;
use Maatwebsite\Excel\Facades\Excel;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
        protected StripeService $stripeService
    ) {}

    public function index(Request $request)
    {
        return $this->paymentService->list($request);
    }

    public function dashboard(Request $request)
    {
        return $this->paymentService->dashboard($request);
    }

    public function show($id)
    {
        return $this->paymentService->details($id);
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
        try {
            $state = $this->stripeService->getSessionReturnState($session_id);
        } catch (Exception $e) {
            Log::error('Stripe success return error: ' . $e->getMessage(), ['session_id' => $session_id]);

            return $this->renderPaymentReturnFailure('customer');
        }

        if ($state['status'] === 'completed') {
            return $this->renderPaymentReturnSuccess($state);
        }

        if ($state['status'] === 'processing') {
            return view('payment-processing', ['session_id' => $session_id]);
        }

        return $this->renderPaymentReturnCancelled($state);
    }

    public function paymentStatus($session_id)
    {
        try {
            $state = $this->stripeService->getSessionReturnState($session_id);

            return response()->json([
                'status'      => $state['status'],
                'source'      => $state['source'],
                'booking_ids' => $state['booking_ids'],
                'message'     => $state['message'],
            ]);
        } catch (Exception $e) {
            Log::error('Stripe payment status error: ' . $e->getMessage(), ['session_id' => $session_id]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Unable to verify payment status.',
            ], 500);
        }
    }

    public function createCheckoutSession(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return sendError('Unauthorized', [], 401);
        }

        $validated = $request->validate([
            'venue_id'                => 'required|exists:venues,id',
            'listing_id'             => 'required|exists:listings,id',
            'date'                    => 'required|date|after_or_equal:today',
            'start_time'               => 'required|date_format:H:i',
            'end_time'                 => 'required|date_format:H:i',
            'payment_method'           => 'required|in:online_payment,deposit_remaining_on_arrival',
            'quantity'                 => 'required|numeric|min:1',
            'add_ons'                  => 'nullable|array',
            'add_ons.*'                => 'exists:add_ons,id',
            'promocode'                => 'nullable|string',
            'notes'                    => 'nullable|string|max:500',
            'phone'                    => 'nullable|string|min:5',
            'recurring_bookings'       => 'nullable|array',
            'recurring_bookings.*.date' => 'required|date|after:today',
            'recurring_bookings.*.start_time' => 'required|date_format:H:i',
            'recurring_bookings.*.end_time' => 'required|date_format:H:i',
            'recurring_bookings.*.price' => 'required|numeric|min:0',
            'recurring_bookings.*.quantity' => 'required|numeric|min:1',
            'recurring_bookings.*.add_ons' => 'nullable|array',
            'use_gift_card_balance'    => 'nullable|boolean',
            'gift_card_balance_used'  => 'nullable|numeric|min:0',
        ]);

        try {
            return $this->paymentService->createCheckoutSession($validated, $user);
        } catch (Exception $e) {
            Log::error('Create checkout session error: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'request' => $request->all(),
            ]);
            return sendError('Failed to create checkout session: ' . $e->getMessage());
        }
    }

    public function cancelPayment()
    {
        $sessionId = request()->get('session_id');
        $source = 'customer';
        $paymentLinkUrl = null;

        if ($sessionId) {
            try {
                $state = $this->stripeService->getSessionReturnState($sessionId);

                if ($state['status'] === 'completed') {
                    return $this->renderPaymentReturnSuccess($state);
                }

                if ($state['status'] === 'processing') {
                    return view('payment-processing', ['session_id' => $sessionId]);
                }

                // Customer left Stripe checkout — expire session and cancel unpaid bookings
                // so browser "back" cannot complete payment on a dead session.
                $state = $this->stripeService->abandonCheckoutSession($sessionId);

                $source = $state['source'] ?? 'customer';

                if ($source === 'admin' && isset($state['session'])) {
                    $paymentLinkUrl = $state['session']->url ?? null;
                }
            } catch (Exception $e) {
                Log::error('Payment cancel return error: ' . $e->getMessage(), [
                    'session_id' => $sessionId,
                ]);
            }
        }

        if ($source === 'admin') {
            return view('payment-cancel', [
                'isAdminPayment'  => true,
                'paymentLinkUrl' => $paymentLinkUrl,
            ]);
        }

        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

        return redirect(rtrim($frontendUrl, '/') . '/payment/cancel');
    }

    protected function renderPaymentReturnSuccess(array $state)
    {
        $bookingIds = $state['booking_ids'] ?? [];
        $source     = $state['source'] ?? 'customer';

        if ($source === 'admin') {
            $bookings = Booking::with(['venue:id,name', 'listing:id,name'])
                ->whereIn('id', $bookingIds)
                ->get();

            return view('payment-success', compact('bookings'));
        }

        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        $redirectUrl = rtrim($frontendUrl, '/') . '/thank-you?booking_ids=' . implode(',', $bookingIds);

        return redirect($redirectUrl);
    }

    protected function renderPaymentReturnCancelled(array $state)
    {
        $source  = $state['source'] ?? 'customer';
        $message = $state['message'] ?? 'Payment was not completed.';

        if ($source === 'admin') {
            return view('payment-cancel', ['error' => $message]);
        }

        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

        return redirect(rtrim($frontendUrl, '/') . '/payment/cancel');
    }

    protected function renderPaymentReturnFailure(string $source = 'customer')
    {
        if ($source === 'admin') {
            return view('payment-cancel', [
                'error' => 'Payment verification failed. Please contact support if this issue persists.',
            ]);
        }

        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

        return redirect(rtrim($frontendUrl, '/') . '/payment/cancel');
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

    public function markPaymentAsReceived(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->hasAnyRole(['super_admin', 'admin'])) {
            return sendError('Unauthorized. You do not have permission to mark payments as received.', [], 403);
        }

        return $this->paymentService->markPaymentAsReceived($request, $id);
    }
}
