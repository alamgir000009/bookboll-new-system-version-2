<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\Schedule;
use Illuminate\Console\Command;

/**
 * Why does this slot cost what it costs — asked of the code that is ACTUALLY running.
 *
 * Reading the slot API from outside cannot tell you whether a fix is deployed: the
 * response looks the same whether a group lost on a tiebreak, was never a candidate,
 * or the web worker is still executing yesterday's bytecode out of OPcache. This runs
 * the same resolvers the customer price goes through, in the CLI process, and prints
 * every step — so a disagreement between this and the live API is itself the answer:
 * the file on disk is right and the web worker has not picked it up.
 *
 *   php artisan pricing:explain 52 monday 22:00
 *   php artisan pricing:explain 52 --all
 */
class ExplainSlotPricing extends Command
{
    protected $signature = 'pricing:explain
        {listing : Listing id}
        {weekday? : sunday..saturday (default: every day)}
        {slot? : Slot start as HH:MM (default: every hour)}
        {--all : Print the full week, every hour}';

    protected $description = 'Show which pricing group wins a slot, and why, using the running code';

    public function handle(): int
    {
        $listing = Listing::with(['pricingGroups', 'pricingCells'])->find($this->argument('listing'));
        if (! $listing) {
            $this->error('Listing not found.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info("Listing {$listing->id} — {$listing->name}   base_price " . (float) $listing->base_price);

        // 1. What is actually stored. If this disagrees with the admin screen, the
        //    save is the problem, not the pricing.
        $this->line('');
        $this->comment('Stored pricing groups');
        $this->table(
            ['id', 'sort', 'name', 'start', 'end', 'price', 'days (raw)'],
            $listing->pricingGroups
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->map(fn ($g) => [
                    $g->id,
                    (int) $g->sort_order,
                    $g->name,
                    $g->start_time,
                    $g->end_time,
                    (float) $g->price_per_hour,
                    is_array($g->days) ? implode(',', $g->days) : (string) $g->days,
                ])->all(),
        );

        $cells = $listing->pricingCells->count();
        $this->line("Painted grid cells: {$cells}" . ($cells ? '  (a painted cell beats the group windows)' : ''));

        // 2. Is the deployed code the fixed code? The old tiebreak was written as
        //    sortBy([fn, fn, fn]), which Collection treats as full comparators.
        $this->line('');
        $this->comment('Running code');
        $ref = new \ReflectionFunction('matchingPricingGroupForSlot');
        $src = implode('', array_slice(
            file($ref->getFileName()),
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1,
        ));
        $fixed = str_contains($src, '<=>');
        $this->line('  ' . $ref->getFileName() . ':' . $ref->getStartLine());
        $this->line($fixed
            ? '  <fg=green>tiebreak: FIXED (rank compare)</>'
            : '  <fg=red>tiebreak: OLD (sortBy([fn,fn,fn]) — picks the wrong group when two overlap)</>');

        $days = $this->argument('weekday')
            ? [strtolower($this->argument('weekday'))]
            : array_keys(Schedule::DAY_INDEX);
        $slots = $this->argument('slot')
            ? [$this->argument('slot')]
            : array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23));

        if (! $this->argument('weekday') && ! $this->option('all')) {
            $slots = array_filter($slots, fn ($s) => $s >= '17:00');
        }

        $this->line('');
        $this->comment('Slot by slot');
        $rows = [];
        foreach ($days as $weekday) {
            foreach ($slots as $slot) {
                $end     = sprintf('%02d:00', ((int) substr($slot, 0, 2) + 1) % 24);
                $covering = $listing->pricingGroups
                    ->filter(fn ($g) => pricingGroupCoversWeekday($g, $weekday))
                    ->filter(fn ($g) => timeSlotFallsInRange($slot, $end, $g->start_time, $g->end_time));

                if ($covering->isEmpty()) {
                    continue;
                }

                $winner  = matchingPricingGroupForSlot($listing, $weekday, $slot, $end);
                $pricing = resolveSlotPricing($listing, $weekday, $slot, $end, null);

                $rows[] = [
                    ucfirst($weekday),
                    $slot,
                    $covering->map(fn ($g) => $g->name . '#' . $g->id)->implode(', '),
                    $winner ? $winner->name . '#' . $winner->id : '—',
                    $pricing['breakdown']['source'] ?? '?',
                    (float) $pricing['price'],
                ];
            }
        }

        $rows
            ? $this->table(['day', 'slot', 'groups covering it', 'winner', 'source', 'price'], $rows)
            : $this->line('  No group covers any slot checked.');

        $this->line('');
        $this->line('<options=bold>If the winner here differs from the live API, the file on disk is correct');
        $this->line('and the web worker is serving cached bytecode — restart PHP (lsphp/php-fpm).</>');
        $this->line('');

        return self::SUCCESS;
    }
}
