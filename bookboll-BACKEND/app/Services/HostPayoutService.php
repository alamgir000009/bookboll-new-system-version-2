<?php

namespace App\Services;

use App\Models\HostPayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Getting an event host paid when their tickets are sold online.
 *
 * Two ways, and the host picks one:
 *
 *   connect — Bookboll creates a Stripe Express account for them and Stripe hosts
 *             the onboarding form. Joiners are charged on Bookboll's own account
 *             with the money transferred onward, so Bookboll's commission comes
 *             off automatically at the moment of payment.
 *
 *   keys    — the host supplies their own Stripe keys and joiners are charged
 *             straight on their account. Every riyal lands with them, so Bookboll's
 *             commission is NOT collected here and has to be billed separately.
 *
 * Either way the host's Stripe account has to say `charges_enabled` before we let
 * them sell anything — a connected account exists long before it may take money.
 */
class HostPayoutService
{
    public function __construct(protected StripeService $stripe) {}

    /** What the host wizard needs to render the payouts box. */
    public function status(User $host)
    {
        $account = HostPayoutAccount::where('user_id', $host->id)->first();

        return sendSuccess('Payout account', ['account' => $this->map($account)]);
    }

    /**
     * Start (or resume) Express onboarding. Stripe hosts the form, so we hand back
     * a one-time link and the host finishes it there.
     */
    public function startConnect(User $host, string $returnUrl, string $refreshUrl)
    {
        $client = $this->platformClient();

        if (! $client) {
            return sendError('Payouts are not configured. Please try again later.', [], 503);
        }

        $account = HostPayoutAccount::where('user_id', $host->id)->first();

        // Their own keys and a connected account are alternatives, not a stack.
        if ($account && $account->mode === 'keys') {
            return sendError('Disconnect your own Stripe keys first, then connect with Stripe.', [], 422);
        }

        try {
            if (! $account?->stripe_account_id) {
                $created = $client->accounts->create([
                    'type'          => 'express',
                    'email'         => $host->email,
                    'business_type' => 'individual',
                    'capabilities'  => [
                        'card_payments' => ['requested' => true],
                        'transfers'     => ['requested' => true],
                    ],
                    'metadata' => ['bookboll_user_id' => (string) $host->id],
                ]);

                $account = HostPayoutAccount::updateOrCreate(
                    ['user_id' => $host->id],
                    [
                        'mode'              => 'connect',
                        'status'            => 'pending',
                        'stripe_account_id' => $created->id,
                        'country'           => $created->country,
                        'livemode'          => $this->platformIsLive(),
                        'connected_at'      => now(),
                    ]
                );
            }

            $link = $client->accountLinks->create([
                'account'     => $account->stripe_account_id,
                'return_url'  => $returnUrl,
                'refresh_url' => $refreshUrl,
                'type'        => 'account_onboarding',
            ]);
        } catch (\Throwable $e) {
            Log::error('Stripe Connect onboarding failed', ['user' => $host->id, 'error' => $e->getMessage()]);

            return sendError('Could not start Stripe onboarding. Please try again.', [], 502);
        }

        return sendSuccess('Onboarding link created', [
            'url'     => $link->url,
            'account' => $this->map($account->fresh()),
        ]);
    }

    /**
     * Ask Stripe what the account can actually do. Called when the host returns
     * from onboarding — the link itself tells us nothing about the outcome.
     */
    public function syncConnect(User $host)
    {
        $account = HostPayoutAccount::where('user_id', $host->id)->where('mode', 'connect')->first();

        if (! $account?->stripe_account_id) {
            return sendError('No connected account to check.', [], 404);
        }

        $client = $this->platformClient();

        if (! $client) {
            return sendError('Payouts are not configured.', [], 503);
        }

        try {
            $remote = $client->accounts->retrieve($account->stripe_account_id);
        } catch (\Throwable $e) {
            Log::error('Stripe Connect sync failed', ['account' => $account->stripe_account_id, 'error' => $e->getMessage()]);

            return sendError('Could not reach Stripe. Please try again.', [], 502);
        }

        $account->update([
            'account_name'      => $remote->business_profile->name ?? $remote->email,
            'country'           => $remote->country,
            'livemode'          => $this->platformIsLive(),
            'charges_enabled'   => (bool) $remote->charges_enabled,
            'payouts_enabled'   => (bool) $remote->payouts_enabled,
            'details_submitted' => (bool) $remote->details_submitted,
            'status'            => $remote->charges_enabled ? 'active' : 'pending',
            'synced_at'         => now(),
        ]);

        return sendSuccess('Account refreshed', ['account' => $this->map($account->fresh())]);
    }

    /**
     * Save the host's own Stripe keys, but only after proving they work: the secret
     * has to authenticate against Stripe and the account has to be able to charge.
     */
    public function saveKeys(User $host, string $secretKey, ?string $publishableKey)
    {
        $secretKey = trim($secretKey);
        $publishableKey = trim((string) $publishableKey);

        if (! preg_match('/^sk_(test|live)_/', $secretKey)) {
            return sendError('That is not a Stripe secret key — it should start with sk_test_ or sk_live_.', [], 422);
        }

        if ($publishableKey !== '' && ! preg_match('/^pk_(test|live)_/', $publishableKey)) {
            return sendError('That is not a Stripe publishable key — it should start with pk_test_ or pk_live_.', [], 422);
        }

        // Prove the key is real and the account can take money, before storing it.
        try {
            $remote = (new StripeClient($secretKey))->accounts->retrieve();
        } catch (\Stripe\Exception\AuthenticationException) {
            return sendError('Stripe rejected that secret key. Check you copied it in full.', [], 422);
        } catch (\Throwable $e) {
            Log::error('Host Stripe key check failed', ['user' => $host->id, 'error' => $e->getMessage()]);

            return sendError('Could not verify that key with Stripe. Please try again.', [], 502);
        }

        if (! $remote->charges_enabled) {
            return sendError('That Stripe account cannot accept payments yet — finish its setup in Stripe first.', [], 422);
        }

        // Test and live keys must not be mixed, or the publishable key won't match
        // the charges the secret creates.
        $secretLive = str_starts_with($secretKey, 'sk_live_');

        if ($publishableKey !== '' && str_starts_with($publishableKey, 'pk_live_') !== $secretLive) {
            return sendError('Your secret and publishable keys are from different modes — use both test or both live.', [], 422);
        }

        $account = HostPayoutAccount::updateOrCreate(
            ['user_id' => $host->id],
            [
                'mode'              => 'keys',
                'status'            => 'active',
                'stripe_account_id' => $remote->id,
                'account_name'      => $remote->business_profile->name ?? $remote->email,
                'country'           => $remote->country,
                'livemode'          => $secretLive,
                'charges_enabled'   => (bool) $remote->charges_enabled,
                'payouts_enabled'   => (bool) $remote->payouts_enabled,
                'details_submitted' => true,
                'secret_key'        => $secretKey,
                'publishable_key'   => $publishableKey ?: null,
                'connected_at'      => now(),
                'synced_at'         => now(),
            ]
        );

        return sendSuccess('Stripe keys verified and saved', ['account' => $this->map($account->fresh())]);
    }

    /** Stop selling through this account. Stripe keeps the account; we forget it. */
    public function disconnect(User $host)
    {
        HostPayoutAccount::where('user_id', $host->id)->delete();

        return sendSuccess('Disconnected', ['account' => null]);
    }

    /**
     * How to charge a joiner for this host's ticket.
     *
     * Connect keeps the charge on Bookboll's account and transfers the host's share,
     * so `application_fee` is Bookboll's cut. Their own keys charge on their account
     * instead, where Bookboll cannot take a fee at all.
     *
     * @return array{client: StripeClient, destination: ?string, applicationFee: bool}|null
     */
    public function chargeContext(User $host): ?array
    {
        $account = HostPayoutAccount::where('user_id', $host->id)->first();

        if (! $account || ! $account->isReady()) {
            return null;
        }

        if ($account->mode === 'keys') {
            return [
                'client'         => new StripeClient($account->secret_key),
                'destination'    => null,
                'applicationFee' => false,
            ];
        }

        $client = $this->platformClient();

        return $client ? [
            'client'         => $client,
            'destination'    => $account->stripe_account_id,
            'applicationFee' => true,
        ] : null;
    }

    /** Whether Bookboll's own Stripe keys are live ones. */
    protected function platformIsLive(): bool
    {
        return str_starts_with(trim((string) config('services.stripe.secret')), 'sk_live_');
    }

    protected function platformClient(): ?StripeClient
    {
        $secret = trim((string) config('services.stripe.secret'));

        return $secret === '' ? null : new StripeClient($secret);
    }

    /** Safe shape for the storefront — never includes the secret key. */
    protected function map(?HostPayoutAccount $a): ?array
    {
        if (! $a) {
            return null;
        }

        return [
            'id'                => $a->uuid,
            'mode'              => $a->mode,
            'status'            => $a->status,
            'account_id'        => $a->stripe_account_id,
            'account_name'      => $a->account_name ?? '',
            'country'           => $a->country ?? '',
            'livemode'          => $a->livemode,
            'charges_enabled'   => $a->charges_enabled,
            'payouts_enabled'   => $a->payouts_enabled,
            'details_submitted' => $a->details_submitted,
            'publishable_key'   => $a->publishable_key ?? '',
            'ready'             => $a->isReady(),
            'connected_at'      => optional($a->connected_at)?->toIso8601String(),
        ];
    }
}
