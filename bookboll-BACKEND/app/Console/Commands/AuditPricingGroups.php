<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\PricingGroup;
use App\Models\Schedule;
use Illuminate\Console\Command;

/**
 * Find pricing groups that cannot do what their card says.
 *
 * Three ways a group goes wrong, all of which have happened on this data:
 *
 *  - DEAD: it covers no bookable slot at all. The admin grid used to draw rows
 *    from the earliest open time across the WHOLE week, so on a listing whose
 *    days open at different times the squares sat on times no customer could
 *    book. A price painted there was never charged by anything.
 *  - OVERLAP: two groups cover the same day and hour. Only one can win, decided
 *    by sort_order then id, so the other's price silently never applies.
 *  - CORRUPT: a day value that is neither an index nor a weekday name — "NaN",
 *    written by an old frontend bug. It matches nothing.
 *
 * Reports by default. --fix only ever strips corrupt day values, because that
 * is the one case with a single correct answer; a dead or overlapping group is
 * a pricing decision and stays the operator's call.
 */
class AuditPricingGroups extends Command
{
    protected $signature = 'pricing:audit {--listing= : Only this listing id} {--fix : Strip corrupt day values}';

    protected $description = 'Report pricing groups that price nothing, collide, or hold corrupt days';

    public function handle(): int
    {
        $listings = Listing::with(['pricingGroups', 'schedules'])
            ->when($this->option('listing'), fn ($q, $id) => $q->where('id', $id))
            ->whereHas('pricingGroups')
            ->get();

        $dead = $overlap = $corrupt = [];

        foreach ($listings as $listing) {
            $slots = $this->bookableSlots($listing);
            $groups = $listing->pricingGroups;

            foreach ($groups as $g) {
                foreach ($this->rawDays($g) as $d) {
                    if (! $this->dayIndex($d)) {
                        $corrupt[] = [$listing->id, $g, $d];
                    }
                }

                $covers = 0;
                foreach ($slots as [$weekday, $start, $end]) {
                    if (pricingGroupCoversWeekday($g, $weekday) && timeSlotFallsInRange($start, $end, $g->start_time, $g->end_time)) {
                        $covers++;
                    }
                }
                if ($covers === 0) {
                    $dead[] = [$listing->id, $g];
                }
            }

            foreach ($slots as [$weekday, $start, $end]) {
                $hit = $groups->filter(fn ($g) => pricingGroupCoversWeekday($g, $weekday)
                    && timeSlotFallsInRange($start, $end, $g->start_time, $g->end_time));
                if ($hit->count() > 1) {
                    $winner = matchingPricingGroupForSlot($listing, $weekday, $start, $end);
                    foreach ($hit as $g) {
                        if ($winner && (int) $g->id !== (int) $winner->id) {
                            $overlap[$listing->id.'|'.$g->id.'|'.$winner->id] = [$listing->id, $g, $winner, $weekday, $start];
                        }
                    }
                }
            }
        }

        $this->section('Groups that price NO bookable slot', $dead, fn ($r) => [
            $r[0], $r[1]->id, $r[1]->name, $r[1]->start_time.'-'.$r[1]->end_time,
            (float) $r[1]->price_per_hour, $this->daysLabel($r[1]),
        ], ['listing', 'id', 'name', 'window', 'price', 'days']);

        $this->section('Groups shadowed by another on at least one slot', array_values($overlap), fn ($r) => [
            $r[0], $r[1]->id.' '.$r[1]->name, (float) $r[1]->price_per_hour,
            $r[2]->id.' '.$r[2]->name, (float) $r[2]->price_per_hour,
            ucfirst($r[3]).' '.$r[4],
        ], ['listing', 'never applies', 'its price', 'loses to', 'charged', 'e.g.']);

        $this->section('Corrupt day values', $corrupt, fn ($r) => [
            $r[0], $r[1]->id, $r[1]->name, json_encode($r[1]->days), $r[2],
        ], ['listing', 'id', 'name', 'days', 'bad value']);

        if ($corrupt && $this->option('fix')) {
            $cleaned = 0;
            foreach (collect($corrupt)->groupBy(fn ($r) => $r[1]->id) as $rows) {
                $g = $rows[0][1];
                $keep = array_values(array_filter($this->rawDays($g), fn ($d) => (bool) $this->dayIndex($d)));
                $g->update(['days' => $keep]);
                $cleaned++;
            }
            $this->info("Stripped corrupt day values from {$cleaned} group(s).");
        } elseif ($corrupt) {
            $this->line('Re-run with --fix to strip the corrupt values (nothing else is touched).');
        }

        if (! $dead && ! $overlap && ! $corrupt) {
            $this->info('No problems found.');
        }
        $this->line('');

        return self::SUCCESS;
    }

    /** @return array<int, array{0:string,1:string,2:string}> weekday, slot start, slot end */
    protected function bookableSlots(Listing $listing): array
    {
        $step = max(5, (int) $listing->slotDurationMinutes());
        $out  = [];

        foreach ($listing->schedules as $row) {
            if (! $row->is_open || ! $row->start_time || ! $row->end_time) {
                continue;
            }
            $open  = $this->mins($row->start_time);
            $close = $this->mins($row->end_time) === 0 ? 24 * 60 : $this->mins($row->end_time);
            $midnight = $this->mins($row->end_time) === 0;

            for ($m = $open; $m < $close; $m += $step) {
                if (! $midnight && $m + $step > $close) {
                    break;
                }
                $out[] = [strtolower((string) $row->day), $this->hm($m), $this->hm($m + $step)];
            }
        }

        return $out;
    }

    protected function rawDays(PricingGroup $g): array
    {
        $d = $g->days;

        return is_array($d) ? $d : array_filter(array_map('trim', explode(',', (string) $d)), fn ($x) => $x !== '');
    }

    protected function dayIndex($d): ?int
    {
        $d = strtolower(trim((string) $d));
        if (is_numeric($d) && (int) $d >= 0 && (int) $d <= 6) {
            return (int) $d + 1;   // +1 so index 0 (Sunday) stays truthy
        }

        return isset(Schedule::DAY_INDEX[$d]) ? Schedule::DAY_INDEX[$d] + 1 : null;
    }

    protected function daysLabel(PricingGroup $g): string
    {
        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $out = [];
        foreach ($this->rawDays($g) as $d) {
            $i = $this->dayIndex($d);
            $out[] = $i ? $names[$i - 1] : "?{$d}";
        }

        return implode(',', $out);
    }

    /**
     * Schedule casts its times to `datetime:H:i`, so the attribute is a Carbon
     * and casting it to string yields a full timestamp — "2026-10-18 15:00:00",
     * whose first five characters are "2026-". Format it properly instead.
     */
    protected function mins($t): int
    {
        if ($t instanceof \Carbon\CarbonInterface) {
            $t = $t->format('H:i');
        }
        [$h, $m] = array_pad(explode(':', substr((string) $t, 0, 5)), 2, 0);

        return ((int) $h) * 60 + (int) $m;
    }

    protected function hm(int $m): string
    {
        $m = min($m, 24 * 60);

        return $m === 24 * 60 ? '00:00' : sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    protected function section(string $title, array $rows, callable $map, array $head): void
    {
        $this->line('');
        if (! $rows) {
            $this->line("<fg=green>✓</> {$title}: none");

            return;
        }
        $this->warn($title.': '.count($rows));
        $this->table($head, array_map($map, $rows));
    }
}
