<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Support\StripeDocumentLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Fill in Stripe's hosted invoice / receipt link for payments Stripe collected.
 *
 * Why a command and not the checkout path: resolving the link needs a Stripe API
 * call, and a booking must never fail because that call did. Membership invoices
 * already arrive with `hosted_invoice_url` on the webhook and are stored
 * immediately; one-off charges are resolved here, from the `charge_id` /
 * `payment_intent_id` the payment already carries. Run on a schedule and new
 * charges pick up their receipt on the next pass, with every historical row
 * filled on the first.
 *
 * Nothing financial is touched: the only columns written are the two URLs.
 */
class BackfillStripeDocumentUrls extends Command
{
    protected $signature = 'payments:backfill-stripe-urls
        {--limit=500 : How many payments to resolve in this pass}
        {--dry-run : Resolve and report without writing}
        {--refresh : Also re-resolve rows that already have a link}';

    protected $description = "Store Stripe's hosted invoice/receipt link on paid online payments";

    public function handle(): int
    {
        $secret = (string) config('services.stripe.secret');
        if ($secret === '') {
            $this->error('No Stripe secret configured — nothing resolved.');

            return self::FAILURE;
        }

        $stripe = new StripeClient($secret);
        $limit  = max(1, (int) $this->option('limit'));
        $dry    = (bool) $this->option('dry-run');

        $rows = Payment::query()
            ->where('status', 'paid')
            ->whereIn('method', ['online', 'card', 'credit_card'])
            ->when(! $this->option('refresh'), fn ($q) => $q
                ->whereNull('stripe_invoice_url')
                ->whereNull('stripe_receipt_url'))
            ->where(function ($q) {
                $q->whereNotNull('charge_id')
                    ->orWhereNotNull('payment_intent_id')
                    ->orWhere('payment_id', 'like', 'in\_%');
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'payment_id', 'charge_id', 'payment_intent_id', 'stripe_invoice_url', 'stripe_receipt_url']);

        if ($rows->isEmpty()) {
            $this->info('Nothing to resolve.');

            return self::SUCCESS;
        }

        $filled = 0;
        $missed = 0;

        foreach ($rows as $payment) {
            $patch = $this->resolve($stripe, $payment);

            if (! $patch) {
                $missed++;
                continue;
            }

            $filled++;
            if ($dry) {
                $this->line("payment {$payment->id}: " . implode(', ', array_keys($patch)));
                continue;
            }
            $payment->forceFill($patch)->save();
        }

        $this->info(($dry ? 'Would fill ' : 'Filled ') . "{$filled} payment(s); {$missed} had no Stripe document.");

        return self::SUCCESS;
    }

    /**
     * An invoice link when the money was billed through a Stripe Invoice,
     * otherwise the charge's receipt. Never both invented: a row that resolves
     * to nothing is left null so the dashboard keeps showing "not available".
     *
     * @return array<string, string>
     */
    protected function resolve(StripeClient $stripe, Payment $payment): array
    {
        // Membership and Premium billing records the Stripe invoice id as the
        // payment reference, which is what makes an invoice row recognisable.
        $reference = (string) $payment->payment_id;
        if (str_starts_with($reference, 'in_')) {
            $url = $this->hostedInvoiceUrl($stripe, $reference);

            return $url ? ['stripe_invoice_url' => $url] : [];
        }

        $url = $this->receiptUrl($stripe, (string) $payment->charge_id, (string) $payment->payment_intent_id);

        return $url ? ['stripe_receipt_url' => $url] : [];
    }

    protected function hostedInvoiceUrl(StripeClient $stripe, string $invoiceId): ?string
    {
        try {
            return StripeDocumentLink::sanitize($stripe->invoices->retrieve($invoiceId, [])->hosted_invoice_url);
        } catch (\Throwable $e) {
            $this->report('invoice', $invoiceId, $e);

            return null;
        }
    }

    protected function receiptUrl(StripeClient $stripe, string $chargeId, string $paymentIntentId): ?string
    {
        if ($chargeId === '' && $paymentIntentId !== '') {
            try {
                $intent   = $stripe->paymentIntents->retrieve($paymentIntentId, []);
                $chargeId = is_string($intent->latest_charge)
                    ? $intent->latest_charge
                    : (string) ($intent->latest_charge->id ?? '');
            } catch (\Throwable $e) {
                $this->report('payment intent', $paymentIntentId, $e);

                return null;
            }
        }

        if ($chargeId === '') {
            return null;
        }

        try {
            return StripeDocumentLink::sanitize($stripe->charges->retrieve($chargeId, [])->receipt_url);
        } catch (\Throwable $e) {
            $this->report('charge', $chargeId, $e);

            return null;
        }
    }

    /** A Stripe object we cannot read is reported, not fatal — the next row still runs. */
    protected function report(string $kind, string $id, \Throwable $e): void
    {
        Log::warning("Could not read Stripe {$kind} for a payment document link", [
            'kind' => $kind, 'id' => $id, 'error' => $e->getMessage(),
        ]);
        $this->warn("  skipped {$kind} {$id}: {$e->getMessage()}");
    }
}
