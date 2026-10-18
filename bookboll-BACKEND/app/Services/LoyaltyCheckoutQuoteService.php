<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\LoyaltyCheckoutQuote;
use App\Support\CommissionPolicy;
use App\Support\LoyaltyCountry;
use App\Support\LoyaltyRedemption;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * How many points this exact checkout may spend — computed by the server, on
 * the server's own prices.
 *
 * Issuing a quote spends nothing. It creates no booking, debits no points and
 * reserves none: it is a priced offer, bound to the account, country, currency
 * and the normalised inputs it was priced from, with an expiry. Points are
 * reserved when payment is actually attempted.
 *
 * Prices are re-derived here. Anything the browser sends about money is
 * ignored, because the funding cap is Bookboll's own share and a client that
 * could inflate that could hand itself a larger discount.
 */
class LoyaltyCheckoutQuoteService
{
    /** Long enough to pay, short enough that prices cannot drift far. */
    public const TTL_MINUTES = 10;

    /** Kinds the server can price today. */
    public const KINDS = ['listing'];

    public function quote(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return sendError('Not authenticated.', [], 401);
        }

        $data = $request->validate([
            'kind'                 => ['required', 'string', Rule::in(self::KINDS)],
            'country_id'           => 'required|integer|exists:countries,id',
            'currency'             => 'required|string|size:3',
            'checkout'             => 'required|array',
            'checkout.listing_id'  => 'required|integer|exists:listings,id',
            'checkout.slots'       => 'nullable|array',
            'checkout.payment_method' => 'nullable|string|max:64',
            'checkout.surface'     => 'nullable|string|in:marketplace,site',
        ], [
            'kind.in' => 'Rewards are not available for this kind of checkout yet.',
        ]);

        $listing = Listing::with('venue')->find($data['checkout']['listing_id']);
        $countryId = (int) $data['country_id'];
        $currency = strtoupper($data['currency']);

        // The order's real currency decides, not the browser's. A mismatch
        // means the client is pricing something other than this listing.
        $listingCurrency = CommissionPolicy::currencyFor($listing);
        if ($listingCurrency !== '' && strtoupper($listingCurrency) !== $currency) {
            return sendError('That currency does not match this booking.', [], 422);
        }

        $program = LoyaltyCountry::program($countryId);
        if (! $program) {
            return $this->unavailable($countryId, $currency, 'Rewards are not available in this country.');
        }

        // Bookboll's share of this order, from the same policy that charges it.
        $totalMinor = Money::toMinor($this->orderTotal($listing, $data['checkout']), $currency);
        $method = ($data['checkout']['payment_method'] ?? '') === 'deposit_remaining_on_arrival' ? 'deposit' : 'online';
        $channel = ($data['checkout']['surface'] ?? 'marketplace') === CommissionPolicy::CHANNEL_SITE
            ? CommissionPolicy::CHANNEL_SITE
            : CommissionPolicy::CHANNEL_MARKETPLACE;

        $policy = CommissionPolicy::quote($listing, $channel, $totalMinor, $currency, $method);

        $balance = LoyaltyCountry::balance($user->id, $countryId);

        $offer = LoyaltyRedemption::quote(
            // A venue booking has no event fee, and its booking fee is already
            // inside the commission — counted once, never twice.
            ['commission' => $policy['fee_minor'], 'event_fee' => 0, 'booking_fee' => 0],
            $policy['total_minor'],
            $policy['due_now_minor'],
            $balance['balance'],
            (float) $program->redeem_rate,
            (int) $program->min_redeem,
            $program->max_redeem_pct === null ? null : (float) $program->max_redeem_pct,
            $currency
        );

        if ($offer['points'] <= 0) {
            return $this->unavailable($countryId, $currency, $offer['reason']);
        }

        $quote = LoyaltyCheckoutQuote::create([
            'quote_id'          => 'lq_'.Str::random(56),
            'user_id'           => $user->id,
            'country_id'        => $countryId,
            'currency'          => $currency,
            'kind'              => $data['kind'],
            'points'            => $offer['points'],
            'discount_minor'    => $offer['discount_minor'],
            'order_total_minor' => $policy['total_minor'],
            'due_now_minor'     => $policy['due_now_minor'],
            'commission_minor'  => $policy['fee_minor'],
            'inputs_hash'       => self::inputsHash($listing, $policy, $countryId, $currency),
            'expires_at'        => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return sendSuccess('Rewards quote', [
            'quote_id'           => $quote->quote_id,
            'country_id'         => $countryId,
            'currency'           => $currency,
            'points'             => $quote->points,
            'discount'           => Money::toMajor($quote->discount_minor, $currency),
            // Explicitly zero rather than absent, so the client can show what
            // funded the reward instead of guessing.
            'bookboll_share'     => [
                'commission'  => Money::toMajor($policy['fee_minor'], $currency),
                'event_fee'   => 0,
                'booking_fee' => 0,
            ],
            'order_total_before' => Money::toMajor($policy['total_minor'], $currency),
            'due_now_before'     => Money::toMajor($policy['due_now_minor'], $currency),
            'expires_at'         => $quote->expires_at->utc()->toIso8601String(),
        ]);
    }

    /**
     * A priced answer of "nothing", which is different from an error: the
     * checkout continues at full price and the balance stays untouched.
     */
    protected function unavailable(int $countryId, string $currency, string $reason)
    {
        return sendSuccess('Rewards quote', [
            'quote_id'   => null,
            'country_id' => $countryId,
            'currency'   => $currency,
            'points'     => 0,
            'discount'   => 0,
            'reason'     => $reason,
        ]);
    }

    /** The server's own price for this order — never the browser's. */
    protected function orderTotal(Listing $listing, array $checkout): float
    {
        $slots = is_array($checkout['slots'] ?? null) ? $checkout['slots'] : [];
        $count = max(1, count($slots));

        return round((float) $listing->base_price * $count, 2);
    }

    /**
     * Fingerprint of everything the offer was priced from. Recomputed when the
     * quote is spent: a changed order no longer matches, so the customer is
     * asked to review rather than charged a stale amount.
     */
    public static function inputsHash(?Listing $listing, array $policy, int $countryId, string $currency): string
    {
        return hash('sha256', json_encode([
            'listing'    => $listing?->id,
            'country'    => $countryId,
            'currency'   => strtoupper($currency),
            'total'      => $policy['total_minor'],
            'due_now'    => $policy['due_now_minor'],
            'fee'        => $policy['fee_minor'],
            'rate'       => $policy['effective_rate_pct'],
            'channel'    => $policy['channel'],
        ]));
    }
}
