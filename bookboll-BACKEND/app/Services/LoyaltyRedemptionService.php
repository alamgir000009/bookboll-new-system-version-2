<?php

namespace App\Services;

use App\Models\LoyaltyCheckoutQuote;
use App\Models\LoyaltyPoint;
use App\Models\User;
use App\Support\CommissionPolicy;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Spending a loyalty quote.
 *
 * Three steps, because payment can redirect: points are RESERVED when the
 * charge is attempted, CONSUMED once it is confirmed, and RELEASED if it is
 * cancelled, fails or expires. Without that, a customer who abandons a Stripe
 * page in one tab could spend the same points again in another.
 *
 * Every refusal readmeAlamgir names — expired, already used, wrong account,
 * wrong country, wrong currency, changed order — is a hard error, not a silent
 * fallback to full price. "Do not silently ignore rewards fields."
 */
class LoyaltyRedemptionService
{
    /**
     * Validate a quote against the order actually being paid for, and reserve
     * its points.
     *
     * Returns the discount in major units, or an error array. The caller must
     * already hold the booking transaction, so the reservation commits or rolls
     * back with the booking rather than stranding points.
     *
     * @return array{ok: true, quote: LoyaltyCheckoutQuote, discount: float}|array{ok: false, error: string}
     */
    public function reserve(
        ?string $quoteId,
        ?int $requestedPoints,
        User $user,
        $listing,
        array $policyQuote,
        int $countryId,
        string $currency
    ): array {
        if (! $quoteId) {
            return ['ok' => false, 'error' => 'No rewards quote was supplied.'];
        }

        $quote = LoyaltyCheckoutQuote::where('quote_id', $quoteId)->lockForUpdate()->first();
        if (! $quote) {
            return ['ok' => false, 'error' => 'That rewards quote could not be found. Please review your booking again.'];
        }

        $hash = LoyaltyCheckoutQuoteService::inputsHash($listing, $policyQuote, $countryId, $currency);

        if (! $quote->usableFor($user->id, $countryId, $currency, $hash)) {
            // Deliberately one message: distinguishing "expired" from "not
            // yours" tells a prober which quote ids exist.
            return ['ok' => false, 'error' => 'This rewards quote is no longer valid for this booking. Please review it again.'];
        }

        // A mismatched point count means the client is asking for something
        // other than what was quoted.
        if ($requestedPoints !== null && (int) $requestedPoints !== (int) $quote->points) {
            return ['ok' => false, 'error' => 'The rewards amount has changed. Please review your booking again.'];
        }

        // The balance is re-checked here, not trusted from the quote: points
        // may have been spent elsewhere since it was issued.
        $spendable = \App\Support\LoyaltyCountry::balance($user->id, $countryId)['balance'];
        if ($spendable < $quote->points) {
            return ['ok' => false, 'error' => 'You no longer have enough points for this booking.'];
        }

        $quote->forceFill(['reserved_at' => now()])->save();

        return [
            'ok'       => true,
            'quote'    => $quote,
            'discount' => Money::toMajor($quote->discount_minor, $currency),
        ];
    }

    /**
     * The charge is confirmed: debit the points for real.
     *
     * Idempotent — a duplicate webhook or a retried confirmation finds the
     * quote already consumed and does nothing, so points cannot be taken twice.
     */
    public function consume(LoyaltyCheckoutQuote $quote, ?int $bookingId = null): void
    {
        DB::transaction(function () use ($quote, $bookingId) {
            $fresh = LoyaltyCheckoutQuote::whereKey($quote->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->isSpent() || $fresh->isReleased()) {
                return;
            }

            LoyaltyPoint::create([
                'user_id'    => $fresh->user_id,
                'points'     => $fresh->points,
                'type'       => 'debit',
                'country_id' => $fresh->country_id,
                'booking_id' => $bookingId,
                'reason'     => 'Rewards applied at checkout',
            ]);

            $fresh->forceFill(['consumed_at' => now()])->save();
        });
    }

    /**
     * The charge did not happen: give the reservation back.
     *
     * Never releases something already consumed — that would hand back points
     * the customer genuinely spent.
     */
    public function release(?LoyaltyCheckoutQuote $quote): void
    {
        if (! $quote) {
            return;
        }

        DB::transaction(function () use ($quote) {
            $fresh = LoyaltyCheckoutQuote::whereKey($quote->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->isSpent() || $fresh->isReleased()) {
                return;
            }

            $fresh->forceFill(['released_at' => now(), 'reserved_at' => null])->save();
        });
    }

    /**
     * The country whose programme governs this booking — the venue's, which is
     * where the booking is actually made. A website country picker is not
     * proof of an order's country.
     */
    public function countryFor($listing): ?int
    {
        return \App\Support\LoyaltyCountry::countryForVenue($listing?->venue);
    }

    /** The currency the booking is charged in. */
    public function currencyFor($listing): string
    {
        return CommissionPolicy::currencyFor($listing);
    }
}
