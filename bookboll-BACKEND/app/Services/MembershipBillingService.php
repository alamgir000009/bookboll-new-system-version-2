<?php

namespace App\Services;

use App\Models\ClassProgram;
use App\Models\ClassSubscription;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyProgram;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Coupon as StripeCoupon;
use Stripe\Stripe;

/**
 * Stripe-backed membership billing.
 *
 * Sign-up redirects to Stripe Checkout in subscription mode (Apple Pay / Google Pay
 * and 3-D Secure come with it, and no card data touches our servers). The trial and
 * every renewal are driven by Stripe; we only react to webhooks.
 *
 * A subscription row is created up front as `incomplete` and carries the enrolment
 * context; the webhook activates it. Rows without stripe_subscription_id predate
 * this and are never touched by billing.
 */
class MembershipBillingService
{
    private const INTERVAL = [
        'weekly'    => ['week', 1],
        'biweekly'  => ['week', 2],
        'monthly'   => ['month', 1],
        'quarterly' => ['month', 3],
        'yearly'    => ['year', 1],
    ];

    public function __construct(protected CustomerProgramService $programs) {}

    /** Build the Checkout session for a membership sign-up. */
    public function createCheckoutSession(User $user, ClassProgram $program, array $payload): array
    {
        $secret = trim((string) config('services.stripe.secret'));
        if ($secret === '') {
            return ['error' => 'Payments are not configured. Please try again later.'];
        }

        if (! $user->uuid) {
            return ['error' => 'Please sign in to continue.'];
        }

        if (! $this->programs->isVisible($program, null)) {
            return ['error' => 'Program not found.'];
        }

        $capacity = (int) ($program->capacity ?: 0);
        if ($capacity > 0 && ClassSubscription::where('program_id', $program->id)->where('status', 'active')->count() >= $capacity) {
            return ['error' => 'This program is full.'];
        }

        $email = strtolower(trim((string) $user->email));
        if ($this->programs->hasLiveMembership($program->id, $user->uuid, $email)) {
            return ['error' => 'You’re already a member of this program — manage it in My Memberships.'];
        }

        $interval = (string) ($program->billing_interval ?: 'monthly');
        if (! isset(self::INTERVAL[$interval])) {
            return ['error' => 'This membership cannot be billed online.'];
        }

        $amount = round((float) ($program->monthly_price ?: 0), 2);
        if ($amount <= 0) {
            return ['error' => 'This membership has no price set.'];
        }

        $currency = strtolower((string) ($program->currency ?: 'AED'));
        $joiningFee = max(0, round((float) ($program->joining_fee ?: 0), 2));
        $discount = min(max(0, round((float) ($payload['discount'] ?? 0), 2)), $amount);
        $loyalty = max(0, round((float) ($payload['loyalty_redeem'] ?? 0), 2));

        Stripe::setApiKey($secret);

        try {
            $user->createOrGetStripeCustomer();
        } catch (\Throwable $e) {
            Log::error('Membership checkout: Stripe customer failed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return ['error' => 'Could not start payment. Please try again.'];
        }

        $trialDays = $this->trialDays($program, $user);

        return DB::transaction(function () use ($user, $program, $payload, $amount, $currency, $interval, $joiningFee, $discount, $loyalty, $trialDays) {
            $sub = ClassSubscription::create([
                'program_id'       => $program->id,
                'customer_id'      => $user->uuid,
                'customer_name'    => $user->name ?: $user->email,
                'customer_email'   => strtolower((string) $user->email),
                'status'           => 'incomplete',
                'trial_status'     => $trialDays > 0 ? 'pending' : 'none',
                'billing_interval' => $interval,
                'monthly_amount'   => $amount,
                'joining_fee'      => $joiningFee,
                'discount_amount'  => $discount,
                'loyalty_redeem'   => $loyalty,
                'country_id'       => $program->venue?->country_id,
                'form_answers'     => $payload['answers'] ?? [],
                'stripe_customer_id' => $user->stripe_id,
            ]);

            try {
                $session = StripeCheckoutSession::create($this->sessionPayload(
                    $user, $program, $sub, $amount, $currency, $interval, $joiningFee, $discount + $loyalty, $trialDays
                ));
            } catch (\Throwable $e) {
                Log::error('Membership checkout failed', ['program' => $program->id, 'error' => $e->getMessage()]);
                throw $e;
            }

            $sub->update(['stripe_checkout_session_id' => $session->id]);

            return ['url' => $session->url, 'session_id' => $session->id, 'subscription_id' => $sub->uuid];
        });
    }

    /** @return array<string, mixed> */
    protected function sessionPayload(
        User $user,
        ClassProgram $program,
        ClassSubscription $sub,
        float $amount,
        string $currency,
        string $interval,
        float $joiningFee,
        float $discount,
        int $trialDays
    ): array {
        [$unit, $count] = self::INTERVAL[$interval];

        $payload = [
            'mode'                      => 'subscription',
            'customer'                  => $user->stripe_id,
            'client_reference_id'       => $sub->uuid,
            'payment_method_collection' => 'always',
            'success_url'               => $this->storefront("/programs/{$program->uuid}?checkout={CHECKOUT_SESSION_ID}"),
            'cancel_url'                => $this->storefront("/programs/{$program->uuid}?checkout=cancelled"),
            'metadata'                  => ['source' => 'membership', 'subscription_uuid' => $sub->uuid],
            'subscription_data'         => [
                'metadata' => [
                    'source'            => 'membership',
                    'subscription_uuid' => $sub->uuid,
                    'program_uuid'      => (string) $program->uuid,
                    'user_id'           => (string) $user->id,
                ],
            ],
            'line_items' => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'    => $currency,
                    'unit_amount' => (int) round($amount * 100),
                    'recurring'   => ['interval' => $unit, 'interval_count' => $count],
                    'product_data' => ['name' => (string) $program->name],
                ],
            ]],
        ];

        if ($trialDays > 0) {
            $payload['subscription_data']['trial_period_days'] = $trialDays;
        }

        // Without a trial the joining fee is a one-time line item charged at checkout.
        // With a trial it must wait for the first real invoice ("charged with your
        // first payment after the free trial"), so the webhook adds it as an invoice
        // item once the subscription exists.
        if ($joiningFee > 0 && $trialDays === 0) {
            $payload['line_items'][] = [
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => (int) round($joiningFee * 100),
                    'product_data' => ['name' => 'Joining fee'],
                ],
            ];
        }

        // Promo + loyalty are a single one-off discount on the first invoice.
        if ($discount > 0) {
            $coupon = StripeCoupon::create([
                'amount_off' => (int) round($discount * 100),
                'currency'   => $currency,
                'duration'   => 'once',
                'name'       => 'Sign-up discount',
            ]);
            $payload['discounts'] = [['coupon' => $coupon->id]];
        }

        return $payload;
    }

    /**
     * Trial length in days — 0 when there's no trial or the provider's intro offer
     * is already used.
     *
     * A program offers either N free days or N free sessions. Stripe trials are
     * time-based, so a session trial is converted at the program's own cadence
     * (1 free session at 2/week ≈ 4 days), which covers those sessions without
     * giving away a longer window than intended.
     */
    protected function trialDays(ClassProgram $program, User $user): int
    {
        if (! $program->free_trial_enabled) {
            return 0;
        }

        $days = (int) ($program->free_trial_days ?: 0);

        if ($days <= 0) {
            $sessions = (int) ($program->free_trial_sessions ?: 0);
            $perWeek = max(1, (int) ($program->sessions_per_week ?: 1));
            $days = $sessions > 0 ? (int) ceil($sessions * 7 / $perWeek) : 0;
        }

        if ($days <= 0) {
            return 0;
        }

        $owner = $program->owner_user_id ?: ($program->mapped_owner_user_id ? (string) $program->mapped_owner_user_id : null);
        if ($owner && $this->programs->hasUsedIntroOffer($user->email, null, $owner)) {
            return 0;
        }

        return $days;
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /** Checkout finished: link the Stripe subscription and open the membership. */
    public function checkoutCompleted(object $session): void
    {
        $sub = $this->subscriptionFor($session->client_reference_id ?? ($session->metadata->subscription_uuid ?? null));
        if (! $sub || $sub->stripe_subscription_id) {
            return;
        }

        $stripeSub = $this->retrieveSubscription($session->subscription ?? null);
        if (! $stripeSub) {
            return;
        }

        $trialEnd = $stripeSub->trial_end ? now()->setTimestamp($stripeSub->trial_end) : null;

        $sub->update([
            'stripe_subscription_id' => $stripeSub->id,
            'stripe_customer_id'     => (string) ($session->customer ?? $sub->stripe_customer_id),
            'stripe_status'          => $stripeSub->status,
            'status'                 => 'active',
            'trial_status'           => $stripeSub->status === 'trialing' ? 'in_trial' : 'none',
            'trial_ends_at'          => $trialEnd,
            'started_at'             => now(),
            'current_period_end'     => $this->periodEnd($stripeSub),
            'next_billing_at'        => $trialEnd ?: $this->periodEnd($stripeSub),
        ]);

        if ($trialEnd && (float) $sub->joining_fee > 0) {
            $this->queueJoiningFee($sub, $stripeSub->id);
        }
    }

    /** Put the joining fee on the invoice that closes the trial. */
    protected function queueJoiningFee(ClassSubscription $sub, string $stripeSubId): void
    {
        try {
            \Stripe\InvoiceItem::create([
                'customer'     => $sub->stripe_customer_id,
                'subscription' => $stripeSubId,
                'amount'       => (int) round((float) $sub->joining_fee * 100),
                'currency'     => strtolower((string) ($sub->program?->currency ?: 'AED')),
                'description'  => 'Joining fee',
            ]);
        } catch (\Throwable $e) {
            Log::error('Joining fee could not be queued', ['sub' => $sub->id, 'error' => $e->getMessage()]);
        }
    }

    /** A paid invoice: record the money, credit the provider, spend loyalty once. */
    public function invoicePaid(object $invoice): void
    {
        $sub = $this->subscriptionByStripeId($invoice->subscription ?? null);
        if (! $sub) {
            return;
        }

        // Stripe retries webhooks — the invoice id is the payment reference, so an
        // already-recorded invoice is a replay and must not charge or debit again.
        $invoiceId = (string) ($invoice->id ?? '');
        if ($invoiceId !== '' && Payment::where('payment_id', $invoiceId)->exists()) {
            return;
        }

        $gross = round(((int) ($invoice->amount_paid ?? 0)) / 100, 2);
        if ($gross <= 0) {
            $this->syncPeriod($sub, $invoice);

            return;
        }

        $isFirst = ! $sub->first_charge_at;

        DB::transaction(function () use ($sub, $invoice, $gross, $isFirst) {
            $loyalty = $isFirst ? $this->spendLoyalty($sub, $this->invoiceDiscount($invoice)) : 0;

            $this->programs->recordSubscriptionCharge($sub, $gross, $loyalty, (string) ($invoice->id ?? ''));

            $sub->update([
                'status'            => 'active',
                'stripe_status'     => 'active',
                'trial_status'      => 'converted',
                'payment_failed_at' => null,
                'first_charge_at'   => $sub->first_charge_at ?: now(),
            ]);
            $this->syncPeriod($sub, $invoice);
        });
    }

    public function invoiceFailed(object $invoice): void
    {
        $sub = $this->subscriptionByStripeId($invoice->subscription ?? null);
        $sub?->update([
            'status'            => 'past_due',
            'stripe_status'     => 'past_due',
            'payment_failed_at' => now(),
        ]);
    }

    /** Status/period changes: trial ending, pauses, provider-side edits. */
    public function subscriptionUpdated(object $stripeSub): void
    {
        $sub = $this->subscriptionByStripeId($stripeSub->id ?? null);
        if (! $sub) {
            return;
        }

        $status = (string) ($stripeSub->status ?? '');
        $sub->update([
            'stripe_status'      => $status,
            'status'             => in_array($status, ['active', 'trialing'], true) ? 'active' : ($status === 'past_due' ? 'past_due' : $sub->status),
            'trial_status'       => $status === 'trialing' ? 'in_trial' : $sub->trial_status,
            'trial_ends_at'      => ($stripeSub->trial_end ?? null) ? now()->setTimestamp($stripeSub->trial_end) : $sub->trial_ends_at,
            'current_period_end' => $this->periodEnd($stripeSub) ?: $sub->current_period_end,
            'next_billing_at'    => $this->periodEnd($stripeSub) ?: $sub->next_billing_at,
        ]);
    }

    public function subscriptionDeleted(object $stripeSub): void
    {
        $sub = $this->subscriptionByStripeId($stripeSub->id ?? null);
        if (! $sub) {
            return;
        }

        $inTrial = $sub->trial_ends_at && $sub->trial_ends_at->isFuture();
        $sub->update([
            'status'                    => 'cancelled',
            'stripe_status'             => 'canceled',
            'trial_status'              => $inTrial ? 'cancelled_in_trial' : $sub->trial_status,
            'cancelled_at'              => now(),
            'cancelled_during_trial_at' => $inTrial ? now() : $sub->cancelled_during_trial_at,
        ]);
    }

    /** Abandoned checkout — drop the placeholder row. */
    public function checkoutExpired(object $session): void
    {
        ClassSubscription::where('stripe_checkout_session_id', $session->id ?? '')
            ->where('status', 'incomplete')
            ->whereNull('stripe_subscription_id')
            ->delete();
    }

    /** Customer cancels: stop at the end of the paid period, keep access until then. */
    public function cancel(ClassSubscription $sub): array
    {
        if (! $sub->stripe_subscription_id) {
            $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return ['ok' => true];
        }

        $secret = trim((string) config('services.stripe.secret'));
        if ($secret === '') {
            return ['error' => 'Payments are not configured.'];
        }

        Stripe::setApiKey($secret);

        try {
            \Stripe\Subscription::update($sub->stripe_subscription_id, ['cancel_at_period_end' => true]);
        } catch (\Throwable $e) {
            Log::error('Membership cancel failed', ['sub' => $sub->id, 'error' => $e->getMessage()]);

            return ['error' => 'Could not cancel right now. Please try again.'];
        }

        $sub->update(['cancel_reason' => 'customer', 'cancelled_at' => now()]);

        return ['ok' => true];
    }

    /**
     * State for the page the customer lands on after Checkout. The webhook is the
     * source of truth, so this reports "processing" until it has run.
     */
    public function checkoutState(User $user, string $sessionId)
    {
        $sub = ClassSubscription::with('program')
            ->where('stripe_checkout_session_id', $sessionId)
            ->where('customer_id', $user->uuid)
            ->first();

        if (! $sub) {
            return sendError('Sign-up not found.', [], 404);
        }

        return sendSuccess('Checkout state', [
            'status'        => $sub->stripe_subscription_id ? 'ready' : 'processing',
            'subscription'  => $this->programs->mapSubscriptionPublic($sub, $sub->program),
            'trial_ends_at' => $sub->trial_ends_at?->toDateString(),
            'program_id'    => $sub->program?->uuid,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Storefront URL the customer returns to after Checkout (FRONTEND_URL). */
    protected function storefront(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }

    protected function subscriptionFor(?string $uuid): ?ClassSubscription
    {
        return $uuid ? ClassSubscription::where('uuid', $uuid)->first() : null;
    }

    protected function subscriptionByStripeId(?string $id): ?ClassSubscription
    {
        return $id ? ClassSubscription::where('stripe_subscription_id', $id)->first() : null;
    }

    protected function retrieveSubscription(?string $id): ?object
    {
        $secret = trim((string) config('services.stripe.secret'));
        if (! $id || $secret === '') {
            return null;
        }

        try {
            Stripe::setApiKey($secret);

            return \Stripe\Subscription::retrieve($id);
        } catch (\Throwable $e) {
            Log::error('Stripe subscription retrieve failed', ['id' => $id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    protected function periodEnd(object $stripeSub): ?\Illuminate\Support\Carbon
    {
        $end = $stripeSub->current_period_end ?? null;

        return $end ? now()->setTimestamp($end) : null;
    }

    protected function syncPeriod(ClassSubscription $sub, object $invoice): void
    {
        $end = $invoice->lines->data[0]->period->end ?? null;
        if ($end) {
            $sub->update(['current_period_end' => now()->setTimestamp($end), 'next_billing_at' => now()->setTimestamp($end)]);
        }
    }

    /** Discount Stripe actually applied to this invoice, in major units. */
    protected function invoiceDiscount(object $invoice): float
    {
        $total = 0;
        foreach (($invoice->total_discount_amounts ?? []) as $d) {
            $total += (int) ($d->amount ?? 0);
        }

        return round($total / 100, 2);
    }

    /**
     * Spend the points redeemed at sign-up. Never spends more than the discount the
     * invoice actually carried, so a coupon that failed to apply can't cost points.
     * The promo discount is subtracted first — the rest is the loyalty portion.
     */
    protected function spendLoyalty(ClassSubscription $sub, float $invoiceDiscount): float
    {
        $amount = min((float) $sub->loyalty_redeem, max(0, $invoiceDiscount - (float) $sub->discount_amount));
        $user = User::where('uuid', $sub->customer_id)->first();
        if (! $user || $amount <= 0) {
            return 0;
        }

        $cfg = LoyaltyProgram::where('country_id', $sub->country_id)->first();
        $rate = (int) ($cfg->redeem_rate ?? LoyaltyProgram::defaults()['redeem_rate']);
        $unit = (float) ($cfg->redeem_value ?? LoyaltyProgram::defaults()['redeem_value']) ?: 1;

        $points = min((int) ceil($amount / $unit * $rate), (int) (LoyaltyPoint::balance($user->id) ?? 0));
        if ($points <= 0) {
            return 0;
        }

        LoyaltyPoint::create([
            'user_id' => $user->id,
            'type'    => 'debit',
            'points'  => $points,
            'reason'  => 'Redeemed on membership sign-up',
        ]);
        $user->updateLoyaltyTier();

        return round(min($amount, $points / $rate * $unit), 2);
    }
}
