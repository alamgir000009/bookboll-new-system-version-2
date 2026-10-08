<?php

namespace App\Console\Commands;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clear Stripe ids left behind by a previous Stripe account.
 *
 * Stripe ids are scoped to the account that issued them. Swap the platform's
 * account and every stored cus_/pm_/sub_/acct_ becomes a reference to something the
 * new account has never heard of — Stripe answers "No such customer: cus_…" and the
 * checkout 500s. Cashier makes this worse by design: createOrGetStripeCustomer()
 * returns a stored id without checking it exists, and only creates one when the
 * column is NULL. So the stale value is never outgrown on its own.
 *
 * Clearing it is the whole repair: the next checkout creates a fresh customer in
 * the new account. Saved cards cannot be rescued the same way — a pm_ belongs to
 * the old account and cannot be moved — so those rows go, and customers re-enter
 * the card once.
 *
 * Dry run by default. Nothing is written without --apply.
 *
 * Everything it changes is written to the activity log. Deleting a customer's saved
 * card has to be answerable for later, and a mass `update()` / `DB::table()->delete()`
 * fires no model events, so Spatie would never see it: the cards are removed one at a
 * time through the model, and the run itself is recorded as a single summary entry.
 */
class ResetStripeAccountLinks extends Command
{
    protected $signature = 'stripe:reset-account-links
                            {--apply : Write the changes. Without it, only report.}';

    protected $description = 'Clear customer/payment-method ids issued by a previous Stripe account';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply ? 'Applying…' : 'Dry run — nothing will be written. Re-run with --apply.');
        $this->newLine();

        $staleCustomers = User::whereNotNull('stripe_id')->where('stripe_id', '!=', '')->count();
        $this->line(sprintf('  users.stripe_id to clear ................ %d', $staleCustomers));

        $savedCards = Schema::hasTable('payment_methods')
            ? DB::table('payment_methods')->whereNotNull('stripe_payment_method_id')->count()
            : 0;
        $this->line(sprintf('  payment_methods to remove .............. %d', $savedCards));

        // Live subscriptions are NOT cleared. A subscription id still exists in the
        // OLD account and may still be billing there; deleting the local row would
        // hide that rather than fix it. Report and let a human decide.
        $liveSubs = 0;
        foreach (['subscriptions' => 'stripe_id', 'business_subscriptions' => 'stripe_subscription_id', 'class_subscriptions' => 'stripe_subscription_id'] as $table => $col) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $col)) {
                $liveSubs += DB::table($table)->whereNotNull($col)->where($col, '!=', '')->count();
            }
        }

        $connect = Schema::hasTable('host_payout_accounts')
            ? DB::table('host_payout_accounts')->whereNotNull('stripe_account_id')->count()
            : 0;

        if ($liveSubs || $connect) {
            $this->newLine();
            $this->warn('  Needs a decision, NOT touched by this command:');
            if ($liveSubs) {
                $this->warn(sprintf('    %d subscription row(s) still point at the old account —', $liveSubs));
                $this->warn('    they may still be charging customers there. Cancel them in the old');
                $this->warn('    dashboard, then re-subscribe those customers on the new account.');
            }
            if ($connect) {
                $this->warn(sprintf('    %d connected host payout account(s) — these were connected to the', $connect));
                $this->warn('    OLD platform account and cannot be transferred. Each host must');
                $this->warn('    reconnect before their events can take online tickets again.');
            }
        }

        if (! $apply) {
            $this->newLine();
            $this->info('Dry run complete.');

            return self::SUCCESS;
        }

        $this->newLine();

        // stripe_id is not among User's logged attributes, and a builder update fires
        // no model events either — so the summary entry below is the only record of
        // this. Keep the ids so the entry can say exactly which were dropped.
        $clearedIds = User::whereNotNull('stripe_id')->where('stripe_id', '!=', '')
            ->pluck('stripe_id', 'id')->all();

        $cleared = User::whereNotNull('stripe_id')->where('stripe_id', '!=', '')
            ->update(['stripe_id' => null]);
        $this->info(sprintf('  cleared %d users.stripe_id — fresh customers are created on next checkout', $cleared));

        $removed = 0;

        if ($savedCards) {
            // One at a time through the model: PaymentMethod logs its own deletions,
            // and DB::table()->delete() would bypass that entirely.
            PaymentMethod::whereNotNull('stripe_payment_method_id')
                ->cursor()
                ->each(function (PaymentMethod $method) use (&$removed) {
                    $method->delete();
                    $removed++;
                });

            $this->info(sprintf('  removed %d saved card(s) — customers re-enter the card once', $removed));
        }

        activity('stripe')
            ->withProperties([
                'cleared_customer_ids'   => $clearedIds,
                'customers_cleared'      => $cleared,
                'saved_cards_removed'    => $removed,
                'subscriptions_untouched' => $liveSubs,
                'connect_accounts_untouched' => $connect,
                'ran_by'                 => auth()->id() ?? 'console',
            ])
            ->log('Stripe account switch: cleared ids issued by the previous account');

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
