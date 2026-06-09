<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\StripeSession;
use BookingPaymentMethod;
use BookingPaymentStatus;
use BookingStatus;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PaymentMethod;
use PaymentStatus;
use PaymentType;
use Stripe\Charge;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeService
{
    public function forPayment(Payment $payment): array
    {
        if ($payment->method !== PaymentMethod::ONLINE) {
            return ['available' => false, 'reason' => 'not_online_payment'];
        }

        $secret = $this->resolveStripeSecret();
        if (! $secret) {
            return [
                'available' => false,
                'reason'    => 'stripe_not_configured',
                'error'     => 'Stripe secret key is not configured.',
            ];
        }

        Stripe::setApiKey($secret);
        $isTestMode = str_starts_with($secret, 'sk_test_');

        $result = [
            'available'       => true,
            'is_test_mode'    => $isTestMode,
            'error'           => null,
            'payment_intent'  => null,
            'charge'          => null,
            'checkout_session'=> null,
            'card'            => null,
            'billing'         => null,
            'customer'        => null,
            'dashboard_links' => [],
        ];

        try {
            $paymentIntent = null;
            $charge          = null;

            if ($payment->payment_intent_id) {
                $paymentIntent = PaymentIntent::retrieve(
                    $payment->payment_intent_id,
                    ['expand' => ['latest_charge.payment_method_details', 'latest_charge.billing_details']]
                );
                $result['payment_intent'] = $this->formatPaymentIntent($paymentIntent);
                $result['dashboard_links']['payment_intent'] = $this->dashboardUrl(
                    'payments',
                    $paymentIntent->id,
                    $isTestMode
                );

                if ($paymentIntent->latest_charge && is_object($paymentIntent->latest_charge)) {
                    $charge = $paymentIntent->latest_charge;
                }
            }

            if ($payment->charge_id) {
                try {
                    $charge = Charge::retrieve(
                        $payment->charge_id,
                        ['expand' => ['payment_method_details', 'billing_details']]
                    );
                } catch (Exception $e) {
                    Log::warning('Stripe charge retrieve failed', [
                        'charge_id'  => $payment->charge_id,
                        'payment_id' => $payment->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            if ($charge) {
                $result['charge'] = $this->formatCharge($charge);
                $result['dashboard_links']['charge'] = $this->dashboardUrl(
                    'payments',
                    $charge->id,
                    $isTestMode
                );

                $card = $charge->payment_method_details->card ?? null;
                if ($card) {
                    $result['card'] = $this->formatCard($card);
                }

                $billing = $charge->billing_details ?? null;
                if ($billing) {
                    $result['billing'] = $this->formatBillingDetails($billing);
                }
            }

            $checkoutSession = $this->resolveCheckoutSession($payment, $paymentIntent);
            if ($checkoutSession) {
                $result['checkout_session'] = $this->formatCheckoutSession($checkoutSession);
                $result['dashboard_links']['checkout_session'] = $this->dashboardUrl(
                    'checkout/sessions',
                    $checkoutSession->id,
                    $isTestMode
                );
            }

            $user = $payment->relationLoaded('user') ? $payment->user : $payment->user()->first();
            if ($user?->stripe_id) {
                $result['customer'] = [
                    'stripe_customer_id' => $user->stripe_id,
                    'dashboard_link'     => $this->dashboardUrl('customers', $user->stripe_id, $isTestMode),
                ];
            }
        } catch (Exception $e) {
            Log::error('Failed to load Stripe payment details', [
                'payment_id' => $payment->id,
                'error'      => $e->getMessage(),
            ]);

            return [
                'available' => false,
                'reason'    => 'stripe_api_error',
                'error'     => $e->getMessage(),
            ];
        }

        if (! $result['payment_intent'] && ! $result['charge'] && ! $result['checkout_session']) {
            $result['available'] = false;
            $result['reason']    = 'no_stripe_ids';
            $result['error']     = 'No Stripe payment intent, charge, or checkout session found for this payment.';
        }

        return $result;
    }

    public function constructWebhookEvent(string $payload, ?string $signatureHeader): \Stripe\Event
    {
        $secret = $this->resolveWebhookSecret();
        if (! $secret) {
            throw new Exception('Stripe webhook secret is not configured.');
        }

        if (! $signatureHeader) {
            throw new Exception('Missing Stripe-Signature header.');
        }

        return Webhook::constructEvent($payload, $signatureHeader, $secret);
    }

    public function handleWebhookEvent(\Stripe\Event $event): void
    {
        $type = $event->type;

        if ($type === 'checkout.session.completed') {
            /** @var StripeCheckoutSession $session */
            $session = $event->data->object;
            if (($session->payment_status ?? null) === 'paid') {
                $this->fulfillPaidCheckoutSession($session->id, $session);
            }

            return;
        }

        if ($type === 'checkout.session.async_payment_succeeded') {
            /** @var StripeCheckoutSession $session */
            $session = $event->data->object;
            $this->fulfillPaidCheckoutSession($session->id, $session);

            return;
        }

        if ($type === 'checkout.session.expired') {
            /** @var StripeCheckoutSession $session */
            $session = $event->data->object;
            $this->abandonCheckoutSession($session->id, cancelPendingBookings: true, expireStripeSession: false);

            return;
        }
    }

    /**
     * Expire an open Stripe Checkout session and optionally cancel linked unpaid customer bookings.
     * Idempotent — safe to call from cancel URL, webhooks, and auto-cancel flows.
     *
     * @return array{status:string,source:string,booking_ids:array,session:?StripeCheckoutSession,message:?string}
     */
    public function abandonCheckoutSession(
        string $sessionId,
        bool $cancelPendingBookings = true,
        bool $expireStripeSession = true
    ): array {
        $secret = $this->resolveStripeSecret();
        if (! $secret) {
            throw new Exception('Stripe secret key is not configured.');
        }

        Stripe::setApiKey($secret);

        $session = StripeCheckoutSession::retrieve($sessionId);
        $source  = $session->metadata['source'] ?? 'customer';
        $dbRow   = StripeSession::where('session_id', $sessionId)->first();

        if ($dbRow?->processed || ($session->payment_status ?? null) === 'paid') {
            return [
                'status'      => 'completed',
                'source'      => $source,
                'booking_ids' => $this->resolveBookingIdsFromSession($session),
                'session'     => $session,
                'message'     => null,
            ];
        }

        $bookingIds = $this->resolveBookingIdsFromSession($session);

        try {
            DB::transaction(function () use (
                $sessionId,
                $session,
                $source,
                $bookingIds,
                $cancelPendingBookings,
                $expireStripeSession
            ) {
                StripeSession::where('session_id', $sessionId)->lockForUpdate()->first();

                if ($expireStripeSession && ($session->status ?? null) === 'open') {
                    StripeCheckoutSession::expire($sessionId);
                    Log::info('Expired Stripe checkout session on abandonment', [
                        'session_id' => $sessionId,
                        'source'     => $source,
                    ]);
                }

                if ($cancelPendingBookings && $source === 'customer' && ! empty($bookingIds)) {
                    $this->cancelUnpaidBookingsForCheckout($bookingIds, 'Payment was not completed.');
                }
            });
        } catch (Exception $e) {
            Log::error('Failed to abandon Stripe checkout session', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }

        return [
            'status'      => 'cancelled',
            'source'      => $source,
            'booking_ids' => $bookingIds,
            'session'     => $session,
            'message'     => 'Payment was not completed.',
        ];
    }

    /**
     * @return array{processed:bool,already_processed:bool,booking_ids:array,source:string,error:?string}
     */
    public function fulfillPaidCheckoutSession(string $sessionId, ?StripeCheckoutSession $session = null): array
    {
        $secret = $this->resolveStripeSecret();
        if (! $secret) {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => 'customer',
                'error'             => 'Stripe secret key is not configured.',
            ];
        }

        Stripe::setApiKey($secret);

        $session = $session ?? StripeCheckoutSession::retrieve($sessionId);

        if (($session->payment_status ?? null) !== 'paid') {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => $session->metadata['source'] ?? 'customer',
                'error'             => 'Payment not completed.',
            ];
        }

        $paymentIntentId = is_string($session->payment_intent)
            ? $session->payment_intent
            : ($session->payment_intent->id ?? null);

        if (! $paymentIntentId) {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => $session->metadata['source'] ?? 'customer',
                'error'             => 'No payment intent on checkout session.',
            ];
        }

        $intent   = PaymentIntent::retrieve($paymentIntentId);
        $chargeId = is_string($intent->latest_charge)
            ? $intent->latest_charge
            : ($intent->latest_charge->id ?? null);

        if (! $chargeId) {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => $session->metadata['source'] ?? 'customer',
                'error'             => 'No charge found for payment.',
            ];
        }

        $userId = $session->metadata['user_id'] ?? null;
        if (! $userId) {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => $session->metadata['source'] ?? 'customer',
                'error'             => 'Missing user in session metadata.',
            ];
        }

        $bookingIds  = $this->resolveBookingIdsFromSession($session);
        $paymentType = $session->metadata['payment_type'] ?? 'full';
        $source      = $session->metadata['source'] ?? 'customer';

        if (empty($bookingIds)) {
            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => [],
                'source'            => $source,
                'error'             => 'No bookings linked to this checkout session.',
            ];
        }

        try {
            $result = DB::transaction(function () use (
                $sessionId,
                $bookingIds,
                $paymentType,
                $source,
                $userId,
                $chargeId,
                $paymentIntentId
            ) {
                $stripeSession = StripeSession::where('session_id', $sessionId)
                    ->lockForUpdate()
                    ->first();

                if (! $stripeSession) {
                    $stripeSession = StripeSession::create([
                        'session_id'  => $sessionId,
                        'booking_id'  => $bookingIds[0] ?? null,
                        'booking_ids' => $bookingIds,
                        'source'      => $source,
                        'processed'   => false,
                    ]);
                }

                if ($stripeSession->processed) {
                    return [
                        'processed'         => true,
                        'already_processed' => true,
                        'booking_ids'       => $bookingIds,
                        'source'            => $source,
                        'error'             => null,
                    ];
                }

                Log::info('Fulfilling Stripe checkout session', [
                    'session_id'   => $sessionId,
                    'booking_ids'  => $bookingIds,
                    'payment_type' => $paymentType,
                    'source'       => $source,
                ]);

                foreach ($bookingIds as $bookingId) {
                    $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();

                    if (! $booking) {
                        throw new Exception("Booking #{$bookingId} not found during Stripe fulfillment.");
                    }

                    if ($booking->status === BookingStatus::CANCELLED) {
                        throw new Exception(
                            "Booking #{$booking->booking_id} is cancelled; refusing Stripe payment fulfillment."
                        );
                    }

                    $this->applyPaymentToBooking(
                        (int) $bookingId,
                        (int) $userId,
                        $paymentType,
                        $chargeId,
                        $paymentIntentId
                    );
                }

                foreach ($bookingIds as $bookingId) {
                    $booking = Booking::find($bookingId);
                    if ($booking) {
                        autoConfirmBookingIfPaid($booking);
                        regenerateBookingInvoice($booking);
                    }
                }

                $stripeSession->update([
                    'processed'   => true,
                    'booking_id'  => $stripeSession->booking_id ?? ($bookingIds[0] ?? null),
                    'booking_ids' => $bookingIds,
                    'source'      => $source,
                ]);

                return [
                    'processed'         => true,
                    'already_processed' => false,
                    'booking_ids'       => $bookingIds,
                    'source'            => $source,
                    'error'             => null,
                ];
            });

            if ($result['processed'] && ! $result['already_processed']) {
                $this->dispatchPaymentReceivedNotifications(
                    $result['booking_ids'],
                    $paymentType,
                    $sessionId
                );
            }

            return $result;
        } catch (Exception $e) {
            Log::error('Stripe checkout fulfillment failed', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);

            return [
                'processed'         => false,
                'already_processed' => false,
                'booking_ids'       => $bookingIds,
                'source'            => $source,
                'error'             => $e->getMessage(),
            ];
        }
    }

    protected function dispatchPaymentReceivedNotifications(
        array $bookingIds,
        string $paymentType,
        string $sessionId
    ): void {
        if (empty($bookingIds)) {
            return;
        }

        try {
            $bookings = Booking::with(['user', 'venue', 'listing', 'payments'])
                ->whereIn('id', $bookingIds)
                ->get();

            if ($bookings->isEmpty()) {
                return;
            }

            $bookingService = app(BookingService::class);

            foreach ($bookings->groupBy('venue_id') as $venueId => $venueBookings) {
                if (! $venueId) {
                    continue;
                }

                $primaryBooking = $venueBookings->first();
                $paidPayment      = $primaryBooking->payments()
                    ->where('status', PaymentStatus::PAID)
                    ->where('method', PaymentMethod::ONLINE)
                    ->latest('id')
                    ->first();

                notifyVenueStaffRefresh((int) $venueId, [
                    'event'             => 'payment_received',
                    'payment_id'        => $paidPayment?->id,
                    'booking_id'        => $primaryBooking->id,
                    'booking_ids'       => $venueBookings->pluck('id')->values()->all(),
                    'venue_id'          => (int) $venueId,
                    'session_id'        => $sessionId,
                    'payment_type'      => $paymentType,
                    'all_paid'          => $venueBookings->every(
                        fn (Booking $b) => $b->payment_status === BookingPaymentStatus::FULLY_PAID
                    ),
                    'booking_confirmed' => $venueBookings->contains(
                        fn (Booking $b) => $b->status === BookingStatus::CONFIRMED
                    ),
                    'source'            => 'stripe',
                ]);
            }

            foreach ($bookings as $booking) {
                $bookingService->sendBookingNotification($booking, 'payment_received');
            }
        } catch (Exception $e) {
            Log::error('Stripe payment notifications failed', [
                'session_id'  => $sessionId,
                'booking_ids' => $bookingIds,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    public function getSessionReturnState(string $sessionId): array
    {
        $secret = $this->resolveStripeSecret();
        if (! $secret) {
            throw new Exception('Stripe secret key is not configured.');
        }

        Stripe::setApiKey($secret);

        $session = StripeCheckoutSession::retrieve($sessionId);
        $source  = $session->metadata['source'] ?? 'customer';
        $dbRow   = StripeSession::where('session_id', $sessionId)->first();

        if ($dbRow?->processed) {
            return [
                'status'      => 'completed',
                'source'      => $source,
                'booking_ids' => $this->resolveBookingIdsFromSession($session),
                'session'     => $session,
                'message'     => null,
            ];
        }

        if (($session->payment_status ?? null) === 'paid') {
            $result = $this->fulfillPaidCheckoutSession($sessionId, $session);

            if ($result['processed']) {
                return [
                    'status'      => 'completed',
                    'source'      => $result['source'],
                    'booking_ids' => $result['booking_ids'],
                    'session'     => $session,
                    'message'     => null,
                ];
            }

            return [
                'status'      => 'processing',
                'source'      => $source,
                'booking_ids' => $result['booking_ids'],
                'session'     => $session,
                'message'     => $result['error'],
            ];
        }

        if (in_array($session->status ?? null, ['expired', 'open'], true) && ($session->payment_status ?? null) !== 'paid') {
            return [
                'status'      => 'cancelled',
                'source'      => $source,
                'booking_ids' => $this->resolveBookingIdsFromSession($session),
                'session'     => $session,
                'message'     => null,
            ];
        }

        return [
            'status'      => 'cancelled',
            'source'      => $source,
            'booking_ids' => [],
            'session'     => $session,
            'message'     => 'Payment was not completed.',
        ];
    }

    public function checkoutReturnUrls(): array
    {
        return [
            'success_url' => url('/payment/success/{CHECKOUT_SESSION_ID}'),
            'cancel_url'  => url('/payment/cancel?session_id={CHECKOUT_SESSION_ID}'),
        ];
    }

    protected function cancelUnpaidBookingsForCheckout(array $bookingIds, string $reason): void
    {
        foreach (array_unique(array_map('intval', $bookingIds)) as $bookingId) {
            if ($bookingId <= 0) {
                continue;
            }

            $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();
            if (! $booking) {
                continue;
            }

            if ($booking->status === BookingStatus::CANCELLED) {
                continue;
            }

            if (
                ! in_array($booking->payment_method, [
                    BookingPaymentMethod::ONLINE_PAYMENT,
                    BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
                ], true)
                || $booking->payment_status !== BookingPaymentStatus::PENDING
            ) {
                continue;
            }

            $previousStatus        = $booking->status;
            $previousPaymentStatus = $booking->payment_status;

            $booking->update([
                'cancelled_by'  => null,
                'cancelled_at'  => now(),
                'cancel_reason' => $reason,
                'status'        => BookingStatus::CANCELLED,
            ]);

            processBookingCancellation(
                $booking,
                $booking->user_id,
                $reason,
                true,
                $previousStatus,
                $previousPaymentStatus
            );

            Log::info('Cancelled unpaid booking after checkout abandonment', [
                'booking_id'  => $booking->id,
                'booking_ref' => $booking->booking_id,
                'reason'      => $reason,
            ]);
        }
    }

    protected function applyPaymentToBooking(
        int $bookingId,
        int $userId,
        string $paymentType,
        string $chargeId,
        string $paymentIntentId
    ): void {
        $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();
        if (! $booking) {
            Log::warning('Booking not found during Stripe fulfillment', ['booking_id' => $bookingId]);

            return;
        }

        if ($booking->status === BookingStatus::CANCELLED) {
            throw new Exception(
                "Booking #{$booking->booking_id} is cancelled; refusing Stripe payment fulfillment."
            );
        }

        if ($paymentType === 'deposit') {
            $depositPayment = $booking->payments()
                ->where('status', PaymentStatus::PENDING)
                ->where('type', PaymentType::DEPOSIT)
                ->first();

            if ($depositPayment) {
                $depositPayment->update([
                    'notes'             => str_replace(
                        'pending',
                        'paid online via Stripe',
                        $depositPayment->notes ?? 'Deposit payment for booking #' . $booking->booking_id
                    ),
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

            return;
        }

        $pendingPayments = $booking->payments()
            ->where('status', PaymentStatus::PENDING)
            ->whereIn('type', [PaymentType::REMAINING, PaymentType::FULL])
            ->get();

        foreach ($pendingPayments as $payment) {
            $notes = $payment->notes ?? "Full payment for booking #{$booking->booking_id}";
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

        $booking->update([
            'full_payment_received' => true,
            'payment_status'        => BookingPaymentStatus::FULLY_PAID,
            'payment_method'        => BookingPaymentMethod::ONLINE_PAYMENT,
        ]);
    }

    protected function resolveBookingIdsFromSession(StripeCheckoutSession $session): array
    {
        $metadata = $session->metadata ?? [];

        $allBookingIdsJson = $metadata['all_booking_ids'] ?? null;
        if ($allBookingIdsJson) {
            $ids = json_decode($allBookingIdsJson, true) ?? [];

            return array_values(array_unique(array_filter(array_map('intval', $ids))));
        }

        $mainBookingId   = $metadata['booking_id'] ?? null;
        $dueBookingsJson = $metadata['due_bookings'] ?? '[]';
        $dueIds          = json_decode($dueBookingsJson, true) ?? [];

        $bookingIds = [];
        if ($mainBookingId) {
            $bookingIds[] = (int) $mainBookingId;
        }
        if (! empty($dueIds)) {
            $bookingIds = array_merge($bookingIds, array_map('intval', $dueIds));
        }

        $dbRow = StripeSession::where('session_id', $session->id)->first();
        if ($dbRow && ! empty($dbRow->booking_ids)) {
            $bookingIds = array_merge($bookingIds, array_map('intval', (array) $dbRow->booking_ids));
        } elseif ($dbRow?->booking_id) {
            $bookingIds[] = (int) $dbRow->booking_id;
        }

        return array_values(array_unique(array_filter($bookingIds)));
    }

    protected function resolveCheckoutSession(Payment $payment, ?PaymentIntent $paymentIntent): ?StripeCheckoutSession
    {
        $dbSession = StripeSession::query()
            ->where('booking_id', $payment->booking_id)
            ->orWhereJsonContains('booking_ids', $payment->booking_id)
            ->latest('id')
            ->first();

        if ($dbSession?->session_id) {
            try {
                return StripeCheckoutSession::retrieve($dbSession->session_id);
            } catch (Exception $e) {
                Log::warning('Stripe checkout session retrieve from DB id failed', [
                    'session_id' => $dbSession->session_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if ($paymentIntent?->id) {
            try {
                $sessions = StripeCheckoutSession::all([
                    'payment_intent' => $paymentIntent->id,
                    'limit'          => 1,
                ]);
                if (! empty($sessions->data[0])) {
                    return $sessions->data[0];
                }
            } catch (Exception $e) {
                Log::warning('Stripe checkout session list by PI failed', [
                    'payment_intent_id' => $paymentIntent->id,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    protected function formatPaymentIntent(PaymentIntent $pi): array
    {
        return [
            'id'                   => $pi->id,
            'status'               => $pi->status,
            'amount'               => $this->formatStripeAmount($pi->amount, $pi->currency),
            'amount_cents'         => $pi->amount,
            'amount_received'      => $this->formatStripeAmount($pi->amount_received ?? 0, $pi->currency),
            'currency'             => strtoupper($pi->currency ?? ''),
            'created_at'           => $this->formatTimestamp($pi->created),
            'description'          => $pi->description,
            'receipt_email'        => $pi->receipt_email,
            'capture_method'       => $pi->capture_method,
            'confirmation_method'  => $pi->confirmation_method,
            'payment_method_types' => $pi->payment_method_types ?? [],
            'latest_charge_id'     => is_string($pi->latest_charge) ? $pi->latest_charge : ($pi->latest_charge->id ?? null),
            'metadata'             => $this->metadataToArray($pi->metadata),
        ];
    }

    protected function formatCharge(Charge $charge): array
    {
        return [
            'id'               => $charge->id,
            'status'           => $charge->status,
            'paid'             => (bool) $charge->paid,
            'refunded'         => (bool) $charge->refunded,
            'amount'           => $this->formatStripeAmount($charge->amount, $charge->currency),
            'amount_cents'     => $charge->amount,
            'amount_refunded'  => $this->formatStripeAmount($charge->amount_refunded ?? 0, $charge->currency),
            'currency'         => strtoupper($charge->currency ?? ''),
            'created_at'       => $this->formatTimestamp($charge->created),
            'failure_code'     => $charge->failure_code,
            'failure_message'  => $charge->failure_message,
            'receipt_url'      => $charge->receipt_url,
            'payment_intent_id'=> is_string($charge->payment_intent) ? $charge->payment_intent : ($charge->payment_intent->id ?? null),
            'metadata'         => $this->metadataToArray($charge->metadata),
        ];
    }

    protected function formatCheckoutSession(StripeCheckoutSession $session): array
    {
        return [
            'id'                 => $session->id,
            'status'             => $session->status,
            'payment_status'     => $session->payment_status,
            'mode'               => $session->mode,
            'amount_total'       => $this->formatStripeAmount($session->amount_total ?? 0, $session->currency),
            'amount_subtotal'    => $this->formatStripeAmount($session->amount_subtotal ?? 0, $session->currency),
            'currency'           => strtoupper($session->currency ?? ''),
            'customer_email'     => $session->customer_email,
            'created_at'         => $this->formatTimestamp($session->created),
            'expires_at'         => $this->formatTimestamp($session->expires_at),
            'payment_intent_id'  => is_string($session->payment_intent) ? $session->payment_intent : ($session->payment_intent->id ?? null),
            'url'                => $session->url,
            'metadata'           => $this->metadataToArray($session->metadata),
        ];
    }

    protected function formatCard(object $card): array
    {
        return [
            'brand'       => $card->brand ?? null,
            'last4'       => $card->last4 ?? null,
            'exp_month'   => $card->exp_month ?? null,
            'exp_year'    => $card->exp_year ?? null,
            'funding'     => $card->funding ?? null,
            'country'     => $card->country ?? null,
            'wallet_type' => $card->wallet?->type ?? null,
        ];
    }

    protected function formatBillingDetails(object $billing): array
    {
        return [
            'name'    => $billing->name ?? null,
            'email'   => $billing->email ?? null,
            'phone'   => $billing->phone ?? null,
            'address' => $billing->address ? [
                'line1'       => $billing->address->line1 ?? null,
                'line2'       => $billing->address->line2 ?? null,
                'city'        => $billing->address->city ?? null,
                'state'       => $billing->address->state ?? null,
                'postal_code' => $billing->address->postal_code ?? null,
                'country'     => $billing->address->country ?? null,
            ] : null,
        ];
    }

    protected function formatStripeAmount(?int $amountCents, ?string $currency): string
    {
        $amountCents = (int) ($amountCents ?? 0);
        $value       = number_format($amountCents / 100, 2, '.', '');
        $code        = strtoupper($currency ?? 'QAR');

        return "{$code} {$value}";
    }

    protected function formatTimestamp(?int $timestamp): ?string
    {
        return $timestamp ? date('c', $timestamp) : null;
    }

    protected function metadataToArray($metadata): array
    {
        if (! $metadata) {
            return [];
        }

        return is_array($metadata) ? $metadata : (array) $metadata;
    }

    protected function dashboardUrl(string $path, string $id, bool $isTestMode): string
    {
        $base = $isTestMode ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';

        return "{$base}/{$path}/{$id}";
    }

    protected function resolveStripeSecret(): ?string
    {
        $configSecret = trim((string) config('services.stripe.secret'));
        if ($configSecret !== '') {
            return $configSecret;
        }

        $settingSecret = trim((string) Setting::where('key', 'stripe_secret_key')->value('value'));

        return $settingSecret !== '' ? $settingSecret : null;
    }

    protected function resolveWebhookSecret(): ?string
    {
        $configSecret = trim((string) config('services.stripe.webhook_secret'));
        if ($configSecret !== '') {
            return $configSecret;
        }

        $settingSecret = trim((string) Setting::where('key', 'stripe_webhook_secret')->value('value'));

        return $settingSecret !== '' ? $settingSecret : null;
    }
}
