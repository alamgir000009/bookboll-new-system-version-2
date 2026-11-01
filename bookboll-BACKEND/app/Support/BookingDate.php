<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;
use Throwable;

/**
 * Booking dates and times, as customers are shown them.
 *
 * `bookings.date` is nullable and `Carbon::parse(null)` returns NOW, so an
 * unguarded parse printed TODAY on the invoice of a booking that had no date.
 * A customer reading that sees the day they booked, not the day they play —
 * exactly the confusion this class exists to stop. A missing value is reported
 * as missing here; it is never silently replaced with the current date.
 *
 * The long form ("Wednesday, 04 November 2026") is deliberate too: a bare
 * `2026-11-04` is read as 11 April by half the world, and a customer who has
 * to guess cannot tell a right date from a wrong one.
 */
class BookingDate
{
    /** Shown in place of a date or time the booking does not carry. */
    public const UNKNOWN = 'Not set';

    /** "Wednesday, 04 November 2026", or null when the booking has no date. */
    public static function label($date): ?string
    {
        return self::format($date, 'l, d F Y');
    }

    /** The same date, or "Not set" — for places that must print something. */
    public static function labelOrUnknown($date): string
    {
        return self::label($date) ?? self::UNKNOWN;
    }

    /** "07:00 PM", or null when there is no time. */
    public static function time($time): ?string
    {
        return self::format($time, 'h:i A');
    }

    /** "07:00 PM - 09:00 PM", or null when neither end is known. */
    public static function range($start, $end): ?string
    {
        $from = self::time($start);
        $to   = self::time($end);

        if ($from === null && $to === null) {
            return null;
        }

        return ($from ?? self::UNKNOWN).' - '.($to ?? self::UNKNOWN);
    }

    /** The same range, or "Not set". */
    public static function rangeOrUnknown($start, $end): string
    {
        return self::range($start, $end) ?? self::UNKNOWN;
    }

    /** The machine-readable booking date ("2026-11-04"), or null. */
    public static function iso($date): ?string
    {
        return self::format($date, 'Y-m-d');
    }

    /**
     * Never throws and never guesses: anything that is not a usable date is
     * null, which every caller above turns into "Not set".
     */
    protected static function format($value, string $format): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->format($format);
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        // '0000-00-00' is what a legacy zero date reads back as.
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return Carbon::parse($value)->format($format);
        } catch (Throwable) {
            return null;
        }
    }
}
