<?php

namespace Database\Seeders;

use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Credit every user with the `customer` role a one-off batch of loyalty points,
 * then re-sync their loyalty tier.
 *
 * IDEMPOTENT: rows are tagged with self::REASON and customers that already carry
 * one are skipped, so re-running never double-credits.
 *
 * Amount defaults to 500 points (100 points = 1 currency unit, so ~5 units of
 * redeemable value, comfortably inside the Bronze tier). Override per run:
 *
 *   php artisan db:seed --class=CustomerLoyaltyPointsSeeder
 *   SEED_LOYALTY_POINTS=1500 php artisan db:seed --class=CustomerLoyaltyPointsSeeder
 */
class CustomerLoyaltyPointsSeeder extends Seeder
{
    /** Ledger marker used for the skip check — changing it re-grants to everyone. */
    public const REASON = 'Loyalty points granted to customers (seeder)';

    protected const DEFAULT_POINTS = 500;

    protected const CHUNK = 500;

    public function run(): void
    {
        $points = (int) env('SEED_LOYALTY_POINTS', self::DEFAULT_POINTS);

        if ($points <= 0) {
            $this->command?->error('SEED_LOYALTY_POINTS must be a positive integer.');

            return;
        }

        $query = User::query()
            ->whereRelation('roles', 'name', 'customer')
            ->whereDoesntHave('loyaltyPoints', fn ($q) => $q->where('reason', self::REASON));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->command?->info('Every customer already has these points — nothing to do.');

            return;
        }

        $this->command?->info("Crediting {$points} points to {$total} customer(s)…");

        // Tier bands are static for the run — load once and map balances in PHP
        // instead of re-querying per user.
        $tiers = LoyaltyTier::active()->ordered()->get();
        $bronzeId = LoyaltyTier::where('slug', 'bronze')->value('id');

        $granted = 0;
        $now = now();

        $query->select('id')->chunkById(self::CHUNK, function ($users) use ($points, $now, $tiers, $bronzeId, &$granted) {
            $ids = $users->pluck('id')->all();

            DB::transaction(function () use ($ids, $points, $now, $tiers, $bronzeId) {
                // Bulk insert: the model's activity-log trait would write one log row
                // per credit, which is pure noise for a bulk grant.
                LoyaltyPoint::insert(array_map(fn ($id) => [
                    'user_id'    => $id,
                    'type'       => 'credit',
                    'points'     => $points,
                    'reason'     => self::REASON,
                    'booking_id' => null,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $ids));

                $this->resyncTiers($ids, $tiers, $bronzeId);
            });

            $granted += count($ids);
            $this->command?->info("  … {$granted} done");
        });

        $this->command?->info("Done. Credited {$points} points to {$granted} customer(s).");
    }

    /**
     * Recompute loyalty_tier_id for the given users from their ledger balance.
     * Mirrors User::calculateLoyaltyTier(), but set-based: one balance query for
     * the chunk plus one UPDATE per distinct tier, rather than ~3 queries per user.
     *
     * @param  list<int>  $ids
     * @param  \Illuminate\Support\Collection<int, LoyaltyTier>  $tiers
     */
    protected function resyncTiers(array $ids, $tiers, ?int $bronzeId): void
    {
        $balances = LoyaltyPoint::query()
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->selectRaw('user_id, COALESCE(SUM(CASE WHEN type = "credit" THEN points ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN type = "debit" THEN points ELSE 0 END), 0) AS balance')
            ->pluck('balance', 'user_id');

        $usersByTier = [];
        foreach ($ids as $id) {
            $tierId = $this->tierIdFor((int) ($balances[$id] ?? 0), $tiers) ?? $bronzeId;
            $usersByTier[$tierId][] = $id;
        }

        foreach ($usersByTier as $tierId => $tierUserIds) {
            User::whereIn('id', $tierUserIds)->update(['loyalty_tier_id' => $tierId ?: null]);
        }
    }

    /** @param  \Illuminate\Support\Collection<int, LoyaltyTier>  $tiers */
    protected function tierIdFor(int $points, $tiers): ?int
    {
        foreach ($tiers as $tier) {
            $max = (int) $tier->max_points;
            if ((int) $tier->min_points <= $points && ($max >= $points || $max === 2147483647)) {
                return (int) $tier->id;
            }
        }

        return null;
    }
}
