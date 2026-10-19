<?php

namespace App\Console\Commands;

use App\Support\BookingPurpose;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What the bookings table is made of, and what needs a human.
 *
 * Mixed evidence — a provider slot block that nonetheless carries a real
 * posted payment — is the case readmeAlamgir says must not be resolved by a
 * rule: "Mixed evidence (a block with a real posted payment) needs manual
 * review, not silent suppression of an actual payment ledger entry."
 *
 * Read-only.
 */
class AuditBookingPurpose extends Command
{
    protected $signature = 'bookings:audit-purpose {--review : List the rows needing manual review}';

    protected $description = 'Report how bookings are classified, and any mixed-evidence rows';

    public function handle(): int
    {
        $rows = DB::table('bookings')
            ->selectRaw('COALESCE(record_purpose, "(unclassified)") as purpose, COUNT(*) as n')
            ->groupBy('purpose')->orderByDesc('n')->get();

        $this->table(['purpose', 'bookings'], $rows->map(fn ($r) => [$r->purpose, $r->n])->all());

        $unclassified = (int) DB::table('bookings')->whereNull('record_purpose')->count();
        if ($unclassified > 0) {
            $this->warn("{$unclassified} booking(s) are unclassified — they still fall back to the old inference.");
        }

        $review = DB::table('bookings')->where('record_purpose', BookingPurpose::REVIEW);
        $count = (int) (clone $review)->count();

        if ($count === 0) {
            $this->info('No mixed-evidence rows: every slot block is free of posted payments.');

            return self::SUCCESS;
        }

        $this->warn("{$count} slot block(s) carry a real posted payment and need a human decision.");
        $this->line('Either the payment is genuine and the row is a sale, or the payment is wrong.');
        $this->line('Neither can be decided by a rule, so nothing was changed for them.');

        if ($this->option('review')) {
            $this->table(
                ['booking', 'date', 'status', 'payment_status'],
                (clone $review)->limit(200)->get(['id', 'date', 'status', 'payment_status'])
                    ->map(fn ($b) => [$b->id, $b->date, $b->status, $b->payment_status])->all()
            );
        }

        return self::SUCCESS;
    }
}
