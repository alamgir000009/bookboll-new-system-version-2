<?php

namespace App\Console\Commands;

use App\Models\LoyaltyPoint;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keep only the loyalty a customer earned booking on bookboll.com.
 *
 * Loyalty is the main marketplace's reward. Bookings entered by staff from the
 * admin dashboard or the admin mobile app, and bookings taken on a provider's
 * own white-label storefront, ran the same earn path and credited points that
 * were never the marketplace's to give.
 *
 * Which movements those are is defined once, on
 * LoyaltyPoint::scopeNotMainMarketplace.
 *
 * The rows are DELETED, not soft-deleted — so unless --no-export is passed the
 * command first writes every row it is about to remove to a CSV, which is the
 * only copy that will exist afterwards.
 *
 * Each affected customer's tier is recomputed, because the tier is derived from
 * the balance and would otherwise keep the rank the deleted points bought.
 *
 * Dry run by default; pass --apply to delete.
 */
class PurgeNonMarketplaceLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:purge-non-marketplace-points
                            {--apply : Delete the movements (default is a dry run)}
                            {--no-export : Skip the CSV copy (not recommended — the delete is permanent)}
                            {--export= : Where to write the CSV (default: storage/app/loyalty-purge-<timestamp>.csv)}
                            {--chunk=500 : Rows per batch}';

    protected $description = 'Permanently delete loyalty earned outside the bookboll.com marketplace';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) $this->option('chunk'));

        $query = fn () => LoyaltyPoint::query()->notMainMarketplace();

        $total = $query()->count();

        if ($total === 0) {
            $this->info('Every loyalty movement already comes from a marketplace booking.');

            return self::SUCCESS;
        }

        $this->summarise($total);

        $userIds = $query()->distinct()->pluck('user_id')->filter()->map(fn ($i) => (int) $i)->all();
        $this->reportBalances($userIds);

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry run. Re-run with --apply to delete permanently.');

            return self::SUCCESS;
        }

        if (! $this->option('no-export')) {
            $path = $this->export($query, $chunk);
            $this->info("Wrote a copy of every row to be deleted: {$path}");
        } else {
            $this->warn('--no-export: deleting without a copy. This cannot be undone.');
        }

        $deleted = 0;
        while (true) {
            $ids = $query()->limit($chunk)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += LoyaltyPoint::whereIn('id', $ids)->delete();
            $this->output->write('.');
        }

        $this->newLine();
        $this->info("Deleted {$deleted} movements.");

        $this->recomputeTiers($userIds);

        return self::SUCCESS;
    }

    /** What is about to go, split by why it matched and whether it is an earn or a spend. */
    protected function summarise(int $total): void
    {
        $byType = LoyaltyPoint::query()->notMainMarketplace()
            ->selectRaw('type, COUNT(*) c, SUM(points) p')->groupBy('type')
            ->get()->keyBy('type');

        $credits = (int) ($byType['credit']->p ?? 0);
        $debits = (int) ($byType['debit']->p ?? 0);

        $whiteLabel = LoyaltyPoint::query()->notMainMarketplace()
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('bookings')
                ->whereColumn('bookings.id', 'loyalty_points.booking_id')
                ->where('bookings.commission_channel', \App\Support\CommissionPolicy::CHANNEL_SITE))
            ->count();

        $this->info("Movements to delete: {$total}   credit points: {$credits}   debit points: {$debits}");
        $this->info('  of which white-label (commission_channel = site): '.$whiteLabel);
        $this->info('  the rest are staff-entered (admin dashboard / admin mobile app).');

        // A movement with no booking cannot be attributed either way, so the
        // scope leaves it alone. Said out loud so the gap is never a surprise.
        $orphans = LoyaltyPoint::whereNull('booking_id')->count();
        if ($orphans > 0) {
            $this->warn("{$orphans} movements have no booking and are NOT touched — provenance unknown.");
        }

        if ($debits > 0) {
            $this->warn("{$debits} DEBIT points match — deleting a redemption hands spent points back. Review first.");
        }
    }

    /**
     * What the delete does to each affected balance.
     *
     * A balance that would go negative means points were spent that this run is
     * about to remove. Reported, never auto-corrected.
     */
    protected function reportBalances(array $userIds): void
    {
        if (! $userIds) {
            return;
        }

        $negative = 0;

        foreach (array_chunk($userIds, 500) as $batch) {
            $balances = DB::table('loyalty_points')
                ->selectRaw('user_id, SUM(CASE WHEN type = ? THEN points ELSE 0 END) - SUM(CASE WHEN type = ? THEN points ELSE 0 END) AS balance', ['credit', 'debit'])
                ->whereIn('user_id', $batch)->groupBy('user_id')->get()->keyBy('user_id');

            $going = DB::table('loyalty_points')
                ->selectRaw('user_id, SUM(CASE WHEN type = ? THEN points ELSE 0 END) - SUM(CASE WHEN type = ? THEN points ELSE 0 END) AS pts', ['credit', 'debit'])
                ->whereIn('id', LoyaltyPoint::query()->notMainMarketplace()->whereIn('user_id', $batch)->select('id'))
                ->groupBy('user_id')->get()->keyBy('user_id');

            foreach ($going as $userId => $row) {
                $after = (int) ($balances[$userId]->balance ?? 0) - (int) $row->pts;
                if ($after < 0) {
                    $negative++;
                    $this->warn("user {$userId}: balance would become {$after}");
                }
            }
        }

        $this->info('Customers affected: '.count($userIds));
        $this->info($negative === 0 ? 'No balance goes negative.' : "{$negative} balances would go negative — listed above.");
    }

    /** The only copy of these rows once the delete has run. */
    protected function export(callable $query, int $chunk): string
    {
        $path = $this->option('export')
            ?: storage_path('app/loyalty-purge-'.now()->format('Y-m-d_His').'.csv');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $handle = fopen($path, 'w');
        fputcsv($handle, ['id', 'user_id', 'booking_id', 'country_id', 'type', 'points', 'reason', 'created_by', 'created_at']);

        $query()->orderBy('id')->chunk($chunk, function ($rows) use ($handle) {
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->id, $row->user_id, $row->booking_id, $row->country_id,
                    $row->type, $row->points, $row->reason, $row->created_by,
                    optional($row->created_at)?->toDateTimeString(),
                ]);
            }
        });

        fclose($handle);

        return $path;
    }

    protected function recomputeTiers(array $userIds): void
    {
        $retiered = 0;
        $failures = 0;

        foreach (array_chunk($userIds, 200) as $batch) {
            foreach (User::whereIn('id', $batch)->get() as $user) {
                $before = $user->loyalty_tier_id;

                // users.loyalty_tier_id is NOT NULL, and calculateLoyaltyTier
                // returns null when no tier matches AND the `bronze` fallback is
                // missing or inactive. The rows are already gone by now, so a
                // tier that cannot be resolved is reported and skipped rather
                // than allowed to abort the run half-done.
                try {
                    $user->updateLoyaltyTier();
                } catch (\Throwable $e) {
                    $failures++;
                    $this->warn("user {$user->id}: tier not recomputed ({$e->getMessage()})");
                    continue;
                }

                if ($user->fresh()->loyalty_tier_id != $before) {
                    $retiered++;
                }
            }
        }

        $this->info('Recomputed tiers for '.count($userIds)." customers ({$retiered} changed).");

        if ($failures > 0) {
            $this->warn("{$failures} tiers could not be recomputed — check that an active `bronze` tier exists.");
        }
    }
}
