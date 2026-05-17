<?php
namespace App\Services;

use App\Models\Payment;
use App\Models\Setting;
use App\Models\StripeSession;
use Exception;
use Illuminate\Support\Facades\Log;
use PaymentMethod;
use Stripe\Charge;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\PaymentIntent;
use Stripe\Stripe;

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
}
