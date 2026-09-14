<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give a currency to payments that were written without one.
 *
 * `payments.currency` was added late and back-filled once, but the create calls
 * were never updated — so every payment made after that migration went in as
 * NULL and reports on the Payments page as "Unknown currency".
 *
 * The currency is inferred from the booking's venue (its override, else its
 * country's), or from the subscription's program for membership rows. A payment
 * with nothing to infer from is reported and left alone: a guess here would put a
 * wrong currency on real money, which is the bug we are fixing, not a fix.
 *
 * Dry run by default; pass --apply to write.
 */
class BackfillPaymentCurrencies extends Command
{
    protected $signature = 'payments:backfill-currencies
                            {--apply : Write the currencies (default is a dry run)}
                            {--chunk=500 : Rows per batch}';

    protected $description = 'Fill in currency on payments that were saved without one';

    /** @var array<int,string> venue id → currency */
    protected array $venueCache = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $query = Payment::query()
            ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', ''));

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Every payment already has a currency.');

            return self::SUCCESS;
        }

        $this->info(($apply ? 'Filling in ' : 'DRY RUN — would fill in ')."{$total} payment(s).");

        $byCurrency = [];
        $unresolved = 0;

        $query->with(['booking:id,venue_id'])
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($rows) use ($apply, &$byCurrency, &$unresolved) {
                foreach ($rows as $p) {
                    $code = $this->resolve($p);

                    if ($code === '') {
                        $unresolved++;
                        continue;
                    }

                    $byCurrency[$code] = ($byCurrency[$code] ?? 0) + 1;

                    if ($apply) {
                        DB::table('payments')->where('id', $p->id)->update(['currency' => $code]);
                    }
                }
            });

        $this->newLine();
        foreach ($byCurrency as $code => $n) {
            $this->line(sprintf('  %-6s %d payment(s)', $code, $n));
        }
        if ($unresolved) {
            $this->warn("  Could not infer a currency for {$unresolved} payment(s) — left untouched.");
        }
        $this->info(($apply ? 'Updated: ' : 'Would update: ').array_sum($byCurrency));

        if (! $apply && $byCurrency) {
            $this->comment('Re-run with --apply to write these.');
        }

        return self::SUCCESS;
    }

    /** @var array<int,string> subscription id → currency */
    protected array $subCache = [];

    protected function resolve(Payment $p): string
    {
        // A membership payment follows its program. Payment has no subscription
        // relation, so this reads the column directly rather than inventing one.
        $subId = (int) ($p->subscription_id ?? 0);
        if ($subId > 0) {
            if (! array_key_exists($subId, $this->subCache)) {
                $code = DB::table('class_subscriptions')
                    ->join('class_programs', 'class_programs.id', '=', 'class_subscriptions.program_id')
                    ->where('class_subscriptions.id', $subId)
                    ->value('class_programs.currency');
                $this->subCache[$subId] = strtoupper(trim((string) ($code ?? '')));
            }
            if ($this->subCache[$subId] !== '') {
                return $this->subCache[$subId];
            }
        }

        $venueId = (int) ($p->booking?->venue_id ?? 0);
        if ($venueId <= 0) {
            return '';
        }
        if (array_key_exists($venueId, $this->venueCache)) {
            return $this->venueCache[$venueId];
        }

        $venue = Venue::with('country.currency')->find($venueId);
        $code  = $venue
            ? strtoupper(trim((string) ($venue->currency_override ?: ($venue->country?->currency?->code ?? ''))))
            : '';

        return $this->venueCache[$venueId] = $code;
    }
}
