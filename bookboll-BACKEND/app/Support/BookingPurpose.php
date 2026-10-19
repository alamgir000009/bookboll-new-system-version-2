<?php

namespace App\Support;

/**
 * What a booking row is, now that it says so.
 *
 * The three kinds sharing the bookings table were previously told apart by
 * inspecting payment_method and payment_status at every call site, which meant
 * every consumer had to remember the rule and some did not. This is the single
 * place that knows it.
 */
class BookingPurpose
{
    public const SALE    = 'marketplace_sale';
    /** A provider reserving a slot for their own customer. Never a sale. */
    public const BLOCK   = 'provider_slot_block';
    public const SESSION = 'membership_session';
    /** A block carrying a real posted payment — for a human, not a rule. */
    public const REVIEW  = 'needs_review';

    /**
     * Restrict a bookings query to genuine marketplace sales.
     *
     * Rows written before the column existed are still judged by the old
     * inference, so a half-migrated database gives the same answer rather than
     * quietly counting every legacy block as a sale.
     */
    public static function scopeSales($query, string $table = 'bookings')
    {
        return $query->where(function ($q) use ($table) {
            $q->where("{$table}.record_purpose", self::SALE)
                ->orWhere(function ($legacy) use ($table) {
                    $legacy->whereNull("{$table}.record_purpose")
                        ->where(fn ($w) => $w
                            ->whereNull("{$table}.payment_method")
                            ->orWhere("{$table}.payment_method", '!=', 'payment_skipped'))
                        ->where(fn ($w) => $w
                            ->whereNull("{$table}.payment_status")
                            ->orWhere("{$table}.payment_status", '!=', 'payment_skipped'));
                });
        });
    }

    /** Provider slot blocks, which the activity dashboard reports as a card. */
    public static function scopeBlocks($query, string $table = 'bookings')
    {
        return $query->where(function ($q) use ($table) {
            $q->where("{$table}.record_purpose", self::BLOCK)
                ->orWhere(function ($legacy) use ($table) {
                    $legacy->whereNull("{$table}.record_purpose")
                        ->where(fn ($w) => $w
                            ->where("{$table}.payment_method", 'payment_skipped')
                            ->orWhere("{$table}.payment_status", 'payment_skipped'));
                });
        });
    }

    /** Is this row a genuine sale? Used where a query is not available. */
    public static function isSale(?object $booking): bool
    {
        if (! $booking) {
            return false;
        }

        $purpose = $booking->record_purpose ?? null;
        if ($purpose !== null) {
            return $purpose === self::SALE;
        }

        return ($booking->payment_method ?? null) !== 'payment_skipped'
            && ($booking->payment_status ?? null) !== 'payment_skipped';
    }
}
