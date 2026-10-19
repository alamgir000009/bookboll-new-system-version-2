<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Fixed rent, split across the calendar months it actually covers.
 *
 * The rule readmeAlamgir fixes for this contract: rent accrues per CALENDAR
 * DAY, at the monthly amount divided by the number of days in THAT month. So
 * the daily rate in February differs from January's, and a month is always
 * whole when the agreement covers all of it.
 *
 * Its worked example, which the tests pin:
 *   October at 3,100 until the 15th, then 6,200 from the 16th
 *   = 15 x 100 + 16 x 200 = 4,700
 *
 * Two things this gets right that a naive implementation does not:
 *
 *   • Rounding is allocated ONCE per month, not per day. Dividing 3,100 by 31
 *     is exact, but 1,000 by 30 is not — rounding each day would drift, so the
 *     final day of a fully covered month absorbs the remainder and the month
 *     sums to exactly the monthly amount.
 *   • Dates are inclusive on both ends, because that is how the agreement is
 *     written, and rent accrues whether or not anybody booked anything.
 */
class RentProration
{
    /**
     * Rent owed for each calendar month between two dates.
     *
     * @param  int  $monthlyMinor  the agreed monthly rent, in minor units
     * @return array<string, int>  'YYYY-MM' => minor units
     */
    public static function monthly(int $monthlyMinor, string $from, string $to): array
    {
        if ($monthlyMinor <= 0) {
            return [];
        }

        $start = CarbonImmutable::parse($from)->startOfDay();
        $end   = CarbonImmutable::parse($to)->startOfDay();
        if ($end->lessThan($start)) {
            return [];
        }

        $out = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $monthEnd   = $cursor->endOfMonth()->startOfDay();
            $segmentEnd = $monthEnd->lessThan($end) ? $monthEnd : $end;

            $daysInMonth = (int) $cursor->daysInMonth;
            $covered     = (int) $cursor->diffInDays($segmentEnd) + 1;   // inclusive

            $key = $cursor->format('Y-m');

            if ($covered >= $daysInMonth) {
                // The whole month: exactly the monthly amount, no rounding at all.
                $out[$key] = ($out[$key] ?? 0) + $monthlyMinor;
            } else {
                // Part of it: round once, here, rather than accumulating a
                // per-day rounding error across the segment.
                $out[$key] = ($out[$key] ?? 0)
                    + (int) round($monthlyMinor * $covered / $daysInMonth);
            }

            $cursor = $monthEnd->addDay();
        }

        return $out;
    }

    /** Total rent across a period, in minor units. */
    public static function total(int $monthlyMinor, string $from, string $to): int
    {
        return array_sum(self::monthly($monthlyMinor, $from, $to));
    }

    /**
     * What one month costs when several agreements covered parts of it.
     *
     * Each agreement contributes its own daily rate for the days it governed,
     * which is what makes the document's October example come out at 4,700
     * rather than an average of the two rents.
     *
     * @param  array<int, array{monthly_minor:int, from:string, to:string}>  $segments
     */
    public static function monthFromSegments(string $month, array $segments): int
    {
        $monthStart = CarbonImmutable::parse($month.'-01')->startOfDay();
        $monthEnd   = $monthStart->endOfMonth()->startOfDay();

        $total = 0;

        foreach ($segments as $segment) {
            $from = CarbonImmutable::parse($segment['from'])->startOfDay();
            $to   = CarbonImmutable::parse($segment['to'])->startOfDay();

            // Clip to the month being asked about.
            $start = $from->greaterThan($monthStart) ? $from : $monthStart;
            $end   = $to->lessThan($monthEnd) ? $to : $monthEnd;

            if ($end->lessThan($start)) {
                continue;
            }

            $total += self::total((int) $segment['monthly_minor'],
                $start->toDateString(), $end->toDateString());
        }

        return $total;
    }
}
