<?php

namespace App\Support;

/**
 * How much of a checkout rewards may pay for.
 *
 * The rule, from readmeAlamgir, is that redemption is funded ENTIRELY by
 * Bookboll's own share:
 *
 *   eligible funding = Bookboll commission
 *                    + Bookboll-owned event fee
 *                    + Bookboll-owned booking fee
 *
 *   redemption <= min(eligible funding,
 *                     order total,
 *                     amount actually collected online today,
 *                     the country's percentage cap,
 *                     the value of the points the customer can spend here)
 *
 * Everything excluded from that sum is excluded for a reason: the full online
 * payment, the merchant's or host's revenue, prize pots, tax collected for
 * others and processor charges are not Bookboll's to give away. The provider
 * payout and the customer's arrival balance come out identical before and
 * after — the reward reduces Bookboll's revenue and nothing else.
 *
 * Zero commission is a legitimate configuration and must stay zero. The old
 * membership path fell back to 15% when it could not find a rate, which
 * invented platform revenue that nobody had charged and then discounted it.
 */
class LoyaltyRedemption
{
    /**
     * @param  array{commission:int,event_fee:int,booking_fee:int}  $share  minor units
     * @param  int    $orderTotalMinor  the order after promotions
     * @param  int    $dueNowMinor      what is actually collected online today
     * @param  int    $balancePoints    points spendable in THIS country
     * @param  float  $redeemRate       points per one unit of currency
     * @param  int    $minRedeem        fewest points the programme will accept
     * @param  float|null $maxPct       percentage cap, null for none
     *
     * @return array{points:int, discount_minor:int, eligible_minor:int, reason:string}
     */
    public static function quote(
        array $share,
        int $orderTotalMinor,
        int $dueNowMinor,
        int $balancePoints,
        float $redeemRate,
        int $minRedeem,
        ?float $maxPct,
        string $currency
    ): array {
        $none = fn (string $why) => [
            'points' => 0, 'discount_minor' => 0, 'eligible_minor' => 0, 'reason' => $why,
        ];

        // Only positive, explicitly Bookboll-owned revenue, counted once.
        $eligible = max(0, (int) ($share['commission'] ?? 0))
            + max(0, (int) ($share['event_fee'] ?? 0))
            + max(0, (int) ($share['booking_fee'] ?? 0));

        if ($eligible <= 0) {
            // Zero commission is valid. There is simply nothing to fund a
            // reward with, and the merchant's share is never raided for one.
            return $none('This booking has no Bookboll fee for rewards to come out of.');
        }
        if ($orderTotalMinor <= 0 || $dueNowMinor <= 0) {
            // A fully arrival-paid booking collects nothing online today, so
            // there is no charge for points to reduce.
            return $none('Points apply to the amount paid online, and nothing is collected online for this booking.');
        }
        if ($redeemRate <= 0) {
            return $none('Rewards are not configured for this country.');
        }

        $cap = min($eligible, $orderTotalMinor, $dueNowMinor);

        if ($maxPct !== null && $maxPct >= 0) {
            $cap = min($cap, Money::percentOf($orderTotalMinor, $maxPct));
        }

        // What the customer's own points are worth here.
        $factor = Money::factor($currency);
        $pointsWorthMinor = (int) floor(($balancePoints / $redeemRate) * $factor);
        $cap = min($cap, max(0, $pointsWorthMinor));

        if ($cap <= 0) {
            return $none('No points can be applied to this booking.');
        }

        // Whole currency units only, matching the programme's conversion, and
        // rounded DOWN so the points charged always cover the discount given.
        $discountMinor = intdiv($cap, $factor) * $factor;
        if ($discountMinor <= 0) {
            return $none('The reward would be less than one ' . ($currency ?: 'unit') . '.');
        }

        $points = (int) ceil(($discountMinor / $factor) * $redeemRate);

        if ($points < $minRedeem) {
            return $none("This programme requires at least {$minRedeem} points.");
        }
        if ($points > $balancePoints) {
            return $none('Not enough points for this booking.');
        }

        return [
            'points'         => $points,
            'discount_minor' => $discountMinor,
            'eligible_minor' => $eligible,
            'reason'         => '',
        ];
    }
}
