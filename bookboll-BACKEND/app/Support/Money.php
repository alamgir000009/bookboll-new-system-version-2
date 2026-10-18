<?php

namespace App\Support;

/**
 * Money as integer minor units, with the right number of them per currency.
 *
 * Everything here used to be `round($amount * 100)`, which silently assumes
 * every currency has two decimal places. It does not: JPY has none, so ¥1000
 * became ¥100000, and KWD/BHD have three, so a dinar lost a decimal place.
 *
 * It also fixes the rounding the commission policy depends on. A 7.5% fee on a
 * total of 100 is 7.50, but `floor(100 * 7.5 / 100)` is 7 — the merchant was
 * quoted one number and charged another, which is exactly what readmeAlamgir
 * forbids: "Do not leave a paid 7.5% fee displayed as 7.50 while the processor
 * charges 7."
 */
class Money
{
    /** Currencies with no minor unit at all. */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Currencies with three. */
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /** How many minor units make one major unit of this currency. */
    public static function exponent(?string $currency): int
    {
        $code = strtoupper(trim((string) $currency));

        if (in_array($code, self::ZERO_DECIMAL, true)) {
            return 0;
        }
        if (in_array($code, self::THREE_DECIMAL, true)) {
            return 3;
        }

        // Two is both the common case and the safe default for an unknown code:
        // it is what every amount in this system was already assumed to be.
        return 2;
    }

    public static function factor(?string $currency): int
    {
        return (int) (10 ** self::exponent($currency));
    }

    /** A major-unit amount (12.34) as minor units (1234). */
    public static function toMinor(float|int|string $amount, ?string $currency): int
    {
        return (int) round(((float) $amount) * self::factor($currency));
    }

    /** Minor units back to major units, for display and storage. */
    public static function toMajor(int $minor, ?string $currency): float
    {
        $factor = self::factor($currency);

        return $factor === 1 ? (float) $minor : round($minor / $factor, self::exponent($currency));
    }

    /**
     * A percentage of an amount, in minor units, rounded half-up.
     *
     * Half-up rather than floor: the fee shown to the merchant and the fee the
     * processor takes have to be the same number, and flooring loses the half
     * that a fractional rate like 7.5% produces.
     */
    public static function percentOf(int $minor, float $percent): int
    {
        if ($minor <= 0 || $percent <= 0) {
            return 0;
        }

        return (int) round($minor * $percent / 100);
    }

    /**
     * Split an amount into a platform fee and what is left, so the two always
     * add back up to exactly the original. The remainder is computed by
     * subtraction rather than its own rounding, which is what stops a rounded
     * fee plus a rounded share from being a unit off the total.
     *
     * @return array{fee: int, remainder: int}
     */
    public static function split(int $minor, float $percent): array
    {
        $fee = self::percentOf($minor, $percent);
        // A fee can never exceed the amount, nor be negative.
        $fee = max(0, min($fee, max(0, $minor)));

        return ['fee' => $fee, 'remainder' => max(0, $minor) - $fee];
    }
}
