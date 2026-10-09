<?php

namespace App\Console\Commands;

use App\Support\PremiumPlan;
use App\Support\StripeCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

/**
 * Reconcile the Stripe product catalogue with what this codebase sells.
 *
 * Products and Prices are created lazily at first checkout, which is required for
 * programmes — a merchant can publish one at any time, so there is nothing to
 * pre-create. But lazy-only leaves two gaps for the PLATFORM's own plan:
 *
 *   1. The very first real subscriber pays the cost of creating it, and if Stripe
 *      refuses at that moment the checkout falls back to an inline ad-hoc product —
 *      quietly reintroducing the thing the catalogue exists to prevent, on a live
 *      sale.
 *   2. Cached ids are scoped to one Stripe account. Switch accounts (or archive a
 *      Price by hand) and every cached id points at something the new account has
 *      never heard of.
 *
 * So this verifies both directions: every id we have cached is checked against
 * Stripe and dropped if it is gone or archived, and Premium's Product + Prices are
 * created up front so the first sale is a cache hit.
 *
 * Safe to re-run. Dry run by default.
 */
class SyncStripeCatalog extends Command
{
    protected $signature = 'stripe:sync-catalog
                            {--apply : Write the repairs (default is a dry run)}';

    protected $description = 'Verify cached Stripe products/prices and pre-create the platform plan';

    public function handle(): int
    {
        $secret = trim((string) config('services.stripe.secret'));

        if ($secret === '') {
            $this->error('Stripe is not configured (services.stripe.secret is empty).');

            return self::FAILURE;
        }

        $apply  = (bool) $this->option('apply');
        $stripe = new StripeClient($secret);

        $this->info($apply ? 'Applying…' : 'Dry run — nothing will be written. Re-run with --apply.');
        $this->newLine();

        // ── 1. Everything we have cached: does it still exist in THIS account? ──
        $map   = $this->cachedMap();
        $stale = [];

        $this->line(sprintf('Checking %d cached id(s) against Stripe…', count($map)));

        foreach ($map as $fingerprint => $id) {
            $isProduct = str_starts_with($fingerprint, 'product:');

            try {
                $object = $isProduct ? $stripe->products->retrieve($id) : $stripe->prices->retrieve($id);

                if (! ($object->active ?? true)) {
                    $stale[$fingerprint] = $id.' (archived)';
                }
            } catch (\Throwable $e) {
                // Belongs to another account, or was deleted.
                $stale[$fingerprint] = $id.' (not found)';
            }
        }

        if ($stale) {
            $this->warn(sprintf('  %d stale entr%s:', count($stale), count($stale) === 1 ? 'y' : 'ies'));
            foreach ($stale as $fingerprint => $why) {
                $this->warn(sprintf('    %-46s %s', $fingerprint, $why));
            }
            $this->line('  Dropping these lets the next checkout recreate them in the current account.');
        } else {
            $this->info('  all good — every cached id exists and is active');
        }

        // ── 2. The platform plan should exist before the first customer ──
        $this->newLine();
        $this->line('Platform plan (Bookboll Premium):');

        $wanted = [
            'monthly' => [PremiumPlan::PRICE_MONTHLY * 100, 'month'],
            'yearly'  => [PremiumPlan::PRICE_YEARLY * 100, 'year'],
        ];

        foreach ($wanted as $label => [$cents, $interval]) {
            $have = $this->cachedPriceFor('premium', $cents, PremiumPlan::CURRENCY, $interval);
            $this->line(sprintf('  %-8s %s %s / %-5s → %s',
                $label,
                strtoupper(PremiumPlan::CURRENCY),
                number_format($cents / 100, 2),
                $interval,
                $have && ! isset($stale[$this->fingerprint('premium', $cents, PremiumPlan::CURRENCY, $interval)])
                    ? $have
                    : 'MISSING — will be created'));
        }

        if (! $apply) {
            $this->newLine();
            $this->info('Dry run complete.');

            return self::SUCCESS;
        }

        // ── Write ──
        $this->newLine();

        if ($stale) {
            $map = array_diff_key($map, $stale);
            $this->writeMap($map);
            StripeCatalog::flush();
            $this->info(sprintf('  dropped %d stale id(s)', count($stale)));
        }

        foreach ($wanted as $label => [$cents, $interval]) {
            $id = StripeCatalog::recurringPrice(
                $stripe, 'premium', 'Bookboll Premium', $cents, PremiumPlan::CURRENCY, $interval, 1, ['plan' => 'premium']
            );

            $this->info(sprintf('  %-8s → %s', $label, $id ?: '*** FAILED — see the log ***'));
        }

        $this->newLine();
        $this->info('Done. Programme products stay lazy — a merchant can publish one at any time.');

        return self::SUCCESS;
    }

    /** @return array<string,string> */
    protected function cachedMap(): array
    {
        $raw = DB::table('settings')->where('key', 'stripe_catalog_prices')->value('value');
        $decoded = $raw ? json_decode((string) $raw, true) : [];

        return is_array($decoded) ? $decoded : [];
    }

    protected function writeMap(array $map): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'stripe_catalog_prices'],
            ['value' => json_encode($map), 'updated_at' => now()]
        );
    }

    protected function fingerprint(string $key, int $amount, string $currency, string $interval, int $count = 1): string
    {
        return implode('|', [$key, $amount, strtolower($currency), $interval, $count]);
    }

    protected function cachedPriceFor(string $key, int $amount, string $currency, string $interval): ?string
    {
        return $this->cachedMap()[$this->fingerprint($key, $amount, $currency, $interval)] ?? null;
    }
}
