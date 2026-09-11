<?php

namespace App\Support;

/**
 * Collapse a day's opening periods into the fewest ranges that describe it.
 *
 * Hours are stored as one row per period. Data imported from the old system
 * often holds a row per half hour — a venue open all day is 48 rows — and the
 * editors render one row each, so a normal week looks like hundreds of entries
 * instead of "open 00:00–24:00".
 *
 * Slot generation already merges internally (ListingService::mergeAdjacentBlocks)
 * so the bookable window has always been right; this is the same idea applied to
 * what the DASHBOARD reads, and it is deliberately read-only — nothing is
 * rewritten in the database.
 *
 * '00:00' as a close time means end-of-day, so it sorts last and swallows
 * anything that runs up to it.
 */
class HourPeriods
{
    private const END_OF_DAY = 1440;

    /**
     * @param  array<int, array{open_time?: string|null, close_time?: string|null}>  $periods
     * @return array<int, array{open_time: string, close_time: string}>
     */
    public static function merge(array $periods): array
    {
        $spans = [];
        foreach ($periods as $p) {
            $open  = self::minutes($p['open_time'] ?? null);
            $close = self::minutes($p['close_time'] ?? null, true);
            if ($open === null || $close === null || $close <= $open) {
                continue;   // unusable or zero-length — drop it
            }
            $spans[] = [$open, $close];
        }

        if (! $spans) {
            return [];
        }

        usort($spans, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $out = [];
        [$curStart, $curEnd] = $spans[0];

        foreach (array_slice($spans, 1) as [$s, $e]) {
            if ($s <= $curEnd) {          // touching or overlapping — extend
                $curEnd = max($curEnd, $e);
                continue;
            }
            $out[] = [$curStart, $curEnd];
            [$curStart, $curEnd] = [$s, $e];
        }
        $out[] = [$curStart, $curEnd];

        return array_map(fn ($r) => [
            'open_time'  => self::hm($r[0]),
            'close_time' => $r[1] >= self::END_OF_DAY ? '00:00' : self::hm($r[1]),
        ], $out);
    }

    /** 'H:i' (or 'H:i:s', or a datetime) to minutes. '00:00' as a close = 1440. */
    private static function minutes(?string $value, bool $isClose = false): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! preg_match('/(\d{1,2}):(\d{2})/', $value, $m)) {
            return null;
        }
        $mins = ((int) $m[1]) * 60 + (int) $m[2];

        return ($isClose && $mins === 0) ? self::END_OF_DAY : $mins;
    }

    private static function hm(int $minutes): string
    {
        $minutes = max(0, min($minutes, self::END_OF_DAY));

        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }

    /**
     * Merge a flat list of dashboard hour rows (one per period) per day,
     * preserving the row shape the dashboard already consumes.
     *
     * @param  array<int, array{location_id?: string, day_of_week: int|null, is_open: bool, open_time: string|null, close_time: string|null}>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function mergeDashboardRows(array $rows): array
    {
        $byDay = [];
        foreach ($rows as $r) {
            $dow = $r['day_of_week'] ?? null;
            if ($dow === null) {
                continue;
            }
            if (! isset($byDay[$dow])) {
                $byDay[$dow] = ['location_id' => $r['location_id'] ?? null, 'open' => [], 'any' => false];
            }
            if (! empty($r['is_open'])) {
                $byDay[$dow]['any'] = true;
                $byDay[$dow]['open'][] = $r;
            }
        }

        $out = [];
        ksort($byDay);
        foreach ($byDay as $dow => $day) {
            if (! $day['any']) {
                $out[] = [
                    'location_id' => $day['location_id'],
                    'day_of_week' => $dow,
                    'is_open'     => false,
                    'open_time'   => null,
                    'close_time'  => null,
                ];
                continue;
            }
            foreach (self::merge($day['open']) as $span) {
                $out[] = [
                    'location_id' => $day['location_id'],
                    'day_of_week' => $dow,
                    'is_open'     => true,
                    'open_time'   => $span['open_time'],
                    'close_time'  => $span['close_time'],
                ];
            }
        }

        return $out;
    }
}
