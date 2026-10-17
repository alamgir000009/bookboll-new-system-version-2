<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\PricingGroup;
use App\Models\Schedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turn painted prices into pricing groups, so the second store can go.
 *
 * `pricing_cells` rows that merely NAME a group hold nothing a group does not
 * already say — the drop migration discards those. A row with its own
 * `direct_price` is different: it is real money that exists nowhere else, so
 * the migration refuses to run while any survive. This converts them.
 *
 * A group is a rectangle — one hour range across a set of days — and painted
 * squares are not. So the squares are cut into contiguous runs per day, and
 * every day sharing the exact same run becomes one group. Eight scattered
 * squares at one price might come out as four groups; that is the honest
 * decomposition, not a rounding of it.
 *
 * Nothing is written unless --apply is given, and every converted square is
 * re-priced through the live resolver afterwards. If a single one would come
 * out at a different price the whole thing rolls back, because the entire point
 * is that the customer pays exactly what they paid before.
 *
 *   php artisan pricing:convert-cells            # report only
 *   php artisan pricing:convert-cells --apply
 */
class ConvertPricingCellsToGroups extends Command
{
    protected $signature = 'pricing:convert-cells
        {--apply : Write the groups and delete the converted cells}
        {--listing= : Only this listing id}';

    protected $description = 'Convert painted direct-price cells into equivalent pricing groups';

    public function handle(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('pricing_cells')) {
            $this->info('pricing_cells is already gone — nothing to convert.');

            return self::SUCCESS;
        }

        $rows = DB::table('pricing_cells')
            ->whereNotNull('direct_price')
            ->when($this->option('listing'), fn ($q, $id) => $q->where('listing_id', $id))
            ->orderBy('listing_id')->orderBy('day_of_week')->orderBy('slot_time')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No painted prices left. `php artisan migrate` can drop the table.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->info($rows->count().' painted price(s) to convert'.($this->option('apply') ? '' : '  (dry run — nothing will be written)'));

        $plan = [];
        foreach ($rows->groupBy('listing_id') as $listingId => $forListing) {
            $listing = Listing::with('pricingGroups')->find($listingId);
            if (! $listing) {
                $this->warn("  listing {$listingId} no longer exists — its ".$forListing->count().' row(s) will just be dropped');

                continue;
            }

            $step = max(15, (int) $listing->slotDurationMinutes());

            foreach ($forListing->groupBy('direct_price') as $price => $atPrice) {
                // day => sorted list of slot start minutes
                $byDay = [];
                foreach ($atPrice as $c) {
                    $byDay[(int) $c->day_of_week][] = $this->toMinutes($c->slot_time);
                }

                // Cut each day into contiguous runs, then collect the days that
                // share an identical run — those are one group.
                $daysForRun = [];
                foreach ($byDay as $dow => $mins) {
                    foreach ($this->runs(array_unique($mins), $step) as $run) {
                        $daysForRun[$run[0].'-'.$run[1]][] = $dow;
                    }
                }

                foreach ($daysForRun as $run => $days) {
                    [$from, $to] = array_map('intval', explode('-', $run));
                    $plan[] = [
                        'listing' => $listing,
                        'price'   => (float) $price,
                        'start'   => $this->hm($from),
                        'end'     => $this->hm($to),
                        'days'    => array_values(array_unique($days)),
                    ];
                }
            }
        }

        $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $this->table(
            ['listing', 'new group', 'start', 'end', 'price', 'days'],
            array_map(fn ($p, $i) => [
                $p['listing']->id,
                $this->groupName($p['price'], $i),
                $p['start'], $p['end'], $p['price'],
                implode(',', array_map(fn ($d) => $names[$d], $p['days'])),
            ], $plan, array_keys($plan)),
        );

        if (! $this->option('apply')) {
            $this->line('Re-run with --apply to write them.');
            $this->line('');

            return self::SUCCESS;
        }

        // What every affected square costs BEFORE, straight from the cells.
        $before = [];
        foreach ($rows as $c) {
            $before[$c->listing_id.'|'.$c->day_of_week.'|'.substr($c->slot_time, 0, 5)] = (float) $c->direct_price;
        }

        DB::beginTransaction();
        try {
            foreach ($plan as $i => $p) {
                PricingGroup::create([
                    'listing_id'     => $p['listing']->id,
                    'name'           => $this->groupName($p['price'], $i),
                    'price_per_hour' => $p['price'],
                    'start_time'     => $p['start'],
                    'end_time'       => $p['end'],
                    'days'           => array_map('strval', $p['days']),
                    'sort_order'     => 0,   // beats a wider group covering the same hour
                ]);
            }

            DB::table('pricing_cells')->whereIn('id', $rows->pluck('id'))->delete();

            // …and what it costs AFTER, through the resolver the customer hits.
            $weekdays = array_flip(Schedule::DAY_INDEX);
            foreach ($before as $key => $was) {
                [$listingId, $dow, $slot] = explode('|', $key);
                $listing = Listing::with('pricingGroups')->find($listingId);
                $step    = max(15, (int) $listing->slotDurationMinutes());
                $end     = $this->hm($this->toMinutes($slot) + $step);

                $group = matchingPricingGroupForSlot($listing, $weekdays[(int) $dow], $slot, $end);
                $now   = $group ? (float) $group->price_per_hour : (float) $listing->base_price;

                if (abs($now - $was) > 0.001) {
                    throw new \RuntimeException(
                        "Conversion would change a price: listing {$listingId} {$weekdays[(int) $dow]} {$slot} "
                        ."was {$was}, would become {$now}. Nothing was written."
                    );
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Converted. Every affected slot still prices identically.');
        $this->line('Now run: php artisan migrate');
        $this->line('');

        return self::SUCCESS;
    }

    /** @param array<int,int> $mins @return array<int,array{0:int,1:int}> */
    protected function runs(array $mins, int $step): array
    {
        sort($mins);
        $out = [];
        $from = $prev = null;
        foreach ($mins as $m) {
            if ($from === null) {
                $from = $prev = $m;

                continue;
            }
            if ($m === $prev + $step) {
                $prev = $m;

                continue;
            }
            $out[] = [$from, $prev + $step];
            $from = $prev = $m;
        }
        if ($from !== null) {
            $out[] = [$from, $prev + $step];
        }

        return $out;
    }

    protected function toMinutes(string $hm): int
    {
        [$h, $m] = array_pad(explode(':', substr($hm, 0, 5)), 2, 0);

        return ((int) $h) * 60 + (int) $m;
    }

    protected function hm(int $mins): string
    {
        $mins %= 24 * 60;

        return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
    }

    protected function groupName(float $price, int $i): string
    {
        return 'Grid '.rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.').' #'.($i + 1);
    }
}
