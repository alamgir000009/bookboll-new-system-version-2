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
                $mainBookingId   = $session->metadata['booking_id'] ?? null;
                $dueBookingsJson = $session->metadata['due_bookings'] ?? '[]';
                $dueIds          = json_decode($dueBookingsJson, true) ?? [];
                $bookingIds      = array_unique(array_filter(array_merge([$mainBookingId], $dueIds)));

                // Redirect to frontend thank you page with booking IDs
                $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
                $bookingIdsParam = implode(',', $bookingIds);
                $redirectUrl = rtrim($frontendUrl, '/') . '/thank-you?booking_ids=' . $bookingIdsParam;

                return redirect($redirectUrl);
            }

            $stripeSession = $existingSession ?? StripeSession::create([
                'session_id' => $session_id,
                'processed'  => false,
            ]);

            if ($session->payment_status !== 'paid') {
                DB::rollBack();
                // Redirect to frontend cancel page if payment not completed
                $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
                $redirectUrl = rtrim($frontendUrl, '/') . '/payment/cancel';
                return redirect($redirectUrl);
            }

            $paymentIntentId = $session->payment_intent;
            $intent          = PaymentIntent::retrieve($paymentIntentId);
            $chargeId        = $intent->latest_charge;

            if (! $chargeId) {
                DB::rollBack();
                Log::error('No charge ID found for payment intent', ['payment_intent_id' => $paymentIntentId]);
                // Redirect to frontend cancel page
                $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
                $redirectUrl = rtrim($frontendUrl, '/') . '/payment/cancel';
                return redirect($redirectUrl);
            }

            $mainBookingId   = $session->metadata['booking_id'] ?? null;
            $userId          = $session->metadata['user_id'] ?? null;
            $dueBookingsJson = $session->metadata['due_bookings'] ?? '[]';
            $dueIds          = json_decode($dueBookingsJson, true) ?? [];

            if (! $userId) {
                DB::rollBack();
                Log::error('No user ID found in session metadata', ['session_id' => $session_id]);
                // Redirect to frontend cancel page
                $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
                $redirectUrl = rtrim($frontendUrl, '/') . '/payment/cancel';
                return redirect($redirectUrl);
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
                // Redirect to frontend cancel page
                $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
                $redirectUrl = rtrim($frontendUrl, '/') . '/payment/cancel';
                return redirect($redirectUrl);
            }

            $paymentType = $session->metadata['payment_type'] ?? 'full'; // 'full' or 'deposit'
            
            foreach ($bookingIds as $bookingId) {
                $booking = Booking::find($bookingId);
                if (! $booking) {
                    Log::warning('Booking not found', ['booking_id' => $bookingId]);
                    continue;
                }

                if ($paymentType === 'deposit') {
                    // Handle deposit payment
                    $depositPayment = $booking->payments()
                        ->where('status', PaymentStatus::PENDING)
                        ->where('type', PaymentType::DEPOSIT)
                        ->first();

                    if ($depositPayment) {
                        $depositPayment->update([
                            'notes'             => str_replace('pending', 'paid online via Stripe', $depositPayment->notes ?? 'Deposit payment for booking #' . $booking->booking_id),
                            'charge_id'         => $chargeId,
                            'payment_intent_id' => $paymentIntentId,
                            'status'            => PaymentStatus::PAID,
                            'method'            => PaymentMethod::ONLINE,
                            'date'              => now()->format('Y-m-d'),
                        ]);
                        
                        $booking->update([
                            'payment_status' => BookingPaymentStatus::DEPOSIT_PAID,
                            'payment_method' => BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
                        ]);
                        
                        $booking->history()->create([
                            'user_id'     => $userId,
                            'action'      => 'payment_received',
                            'description' => "Deposit payment #{$depositPayment->id} marked as PAID via Stripe. Charge ID: {$chargeId}",
                        ]);
                    }
                } else {
                    // Handle full payment - update all pending payments (FULL or REMAINING)
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
                    
                    $booking->update([
                        'full_payment_received' => true,
                        'payment_status'        => BookingPaymentStatus::FULLY_PAID,
                        'payment_method'        => BookingPaymentMethod::ONLINE_PAYMENT,
                    ]);
                }
                
                // Auto-confirm booking if fully paid
                if ($booking->payment_status === BookingPaymentStatus::FULLY_PAID) {
                    autoConfirmBookingIfPaid($booking);
                    regenerateBookingInvoice($booking);
                }
            }

            foreach ($bookingIds as $bookingId) {
                $booking = Booking::find($bookingId);
                if ($booking) {
                    autoConfirmBookingIfPaid($booking);
                    regenerateBookingInvoice($booking);
                }
            }

            $stripeSession->update(['processed' => true]);

            DB::commit();

            // Redirect to frontend thank you page with booking IDs
            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $bookingIdsParam = implode(',', $bookingIds);
            $redirectUrl = rtrim($frontendUrl, '/') . '/thank-you?booking_ids=' . $bookingIdsParam;

            return redirect($redirectUrl);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Stripe success route error: ' . $e->getMessage(), ['session_id' => $session_id]);
            // Redirect to frontend cancel page on error
            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $redirectUrl = rtrim($frontendUrl, '/') . '/payment/cancel';
            return redirect($redirectUrl);
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
        
        if ($sessionId) {
            try {
                Stripe::setApiKey(config('services.stripe.secret'));
                $session = \Stripe\Checkout\Session::retrieve($sessionId);
                
                $bookingId = $session->metadata['booking_id'] ?? null;
                
                if ($bookingId) {
                    DB::beginTransaction();
                    
                    $booking = Booking::find($bookingId);
                    if ($booking && in_array($booking->status, [\BookingStatus::PENDING, \BookingStatus::CONFIRMED])) {
                        $booking->update([
                            'status' => \BookingStatus::CANCELLED,
                            'cancelled_at' => now(),
                            'cancelled_by' => $booking->user_id,
                            'cancel_reason' => 'Payment cancelled by customer',
                        ]);
                        
                        $booking->history()->create([
                            'user_id' => $booking->user_id,
                            'action' => 'booking_cancelled',
                            'description' => 'Booking cancelled due to payment cancellation',
                        ]);
                    }
                    
                    DB::commit();
                }
            } catch (Exception $e) {
                DB::rollBack();
                Log::error('Payment cancellation error: ' . $e->getMessage(), [
                    'session_id' => $sessionId,
                ]);
            }
        }
        
        // Redirect to frontend payment cancel page
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        $redirectUrl = rtrim($frontendUrl, '/') . '/payment/cancel';

        return redirect($redirectUrl);
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
