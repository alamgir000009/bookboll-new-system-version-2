<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Services\ProviderPlanService;
use App\Support\PremiumPlan;
use Illuminate\Console\Command;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

/**
 * Reconcile Bookboll Premium plans against Stripe.
 *
 * `businesses.plan` and `business_subscriptions` are written by the
 * checkout.session.completed / customer.subscription.* webhooks. If a webhook was
 * missed — not subscribed, delivered while the app was down, or fired before the
 * endpoint existed — Stripe is billing a business that the dashboard still shows
 * as Free, with no subscription to cancel.
 *
 * This reads Stripe (the authority on what is actually being billed) and repairs
 * the local rows through the SAME code the webhook uses, so there is one
 * definition of what a plan means.
 *
 * Dry run by default; pass --apply to write.
 */
class SyncProviderPlansFromStripe extends Command
{
    protected $signature = 'plan:sync-from-stripe
                            {--apply : Write the repairs (default is a dry run)}
                            {--business= : Only this business id}';

    protected $description = 'Repair Premium plan records from Stripe (for missed webhooks)';

    public function handle(ProviderPlanService $plans): int
    {
        $apply  = (bool) $this->option('apply');
        $onlyId = $this->option('business') ? (int) $this->option('business') : null;

        $secret = trim((string) config('services.stripe.secret'));
        if ($secret === '') {
            $this->error('Stripe is not configured.');

            return self::FAILURE;
        }
        Stripe::setApiKey($secret);

        // Every Premium subscription Stripe knows about, newest first.
        $byBusiness = [];
        foreach (StripeSubscription::all(['status' => 'all', 'limit' => 100])->autoPagingIterator() as $sub) {
            if ((string) ($sub->metadata['source'] ?? '') !== PremiumPlan::SOURCE_PLAN) {
                continue;
            }
            $bid = (int) ($sub->metadata['business_id'] ?? 0);
            if ($bid <= 0 || ($onlyId && $bid !== $onlyId)) {
                continue;
            }
            $byBusiness[$bid][] = $sub;
        }

        if (! $byBusiness) {
            $this->info('No Premium subscriptions found in Stripe'.($onlyId ? " for business {$onlyId}." : '.'));

            return self::SUCCESS;
        }

        $repaired = 0;
        $already  = 0;
        $dupes    = 0;

        foreach ($byBusiness as $bid => $subs) {
            $business = Business::find($bid);
            if (! $business) {
                $this->warn("  business {$bid}: referenced by Stripe but not in this database — skipped");
                continue;
            }

            // Entitling ones first, then newest: that is the subscription actually
            // governing the account today.
            usort($subs, function ($a, $b) {
                $ae = in_array($a->status, BusinessSubscription::ENTITLING, true) ? 1 : 0;
                $be = in_array($b->status, BusinessSubscription::ENTITLING, true) ? 1 : 0;

                return $be <=> $ae ?: ($b->created <=> $a->created);
            });

            $live = array_values(array_filter($subs, fn ($s) => in_array($s->status, BusinessSubscription::ENTITLING, true)));
            if (count($live) > 1) {
                $dupes++;
                $this->warn(sprintf(
                    '  business %d ("%s") has %d LIVE subscriptions in Stripe: %s — they will each start billing.',
                    $bid, $business->name, count($live), implode(', ', array_map(fn ($s) => $s->id, $live))
                ));
            }

            $winner = $subs[0];
            $local  = BusinessSubscription::where('stripe_subscription_id', $winner->id)->first();
            $planNow = (string) ($business->plan ?: 'free');
            $shouldEntitle = in_array($winner->status, BusinessSubscription::ENTITLING, true);
            $wantPlan = $shouldEntitle ? 'premium' : 'free';

            $needsRow  = ! $local;
            $needsPlan = $planNow !== $wantPlan;

            if (! $needsRow && ! $needsPlan && (string) $local->status === (string) $winner->status) {
                $already++;
                continue;
            }

            $this->line(sprintf(
                '  business %-4d "%s"', $bid, $business->name
            ));
            $this->line(sprintf(
                '      stripe : %s  status=%s  trial_end=%s  cancel_at_period_end=%s',
                $winner->id, $winner->status,
                $winner->trial_end ? date('Y-m-d', $winner->trial_end) : '-',
                var_export((bool) $winner->cancel_at_period_end, true)
            ));
            $this->line(sprintf(
                '      local  : row=%s  status=%s  plan=%s   →   plan=%s',
                $local ? '#'.$local->id : 'MISSING',
                $local->status ?? '-', $planNow, $wantPlan
            ));

            $repaired++;
            if (! $apply) {
                continue;
            }

            // Reuse the service so a repaired row is identical to a webhook-built one.
            $plans->syncSubscriptionFromStripe($business, $winner);
        }

        $this->newLine();
        $this->info(($apply ? 'Repaired: ' : 'Would repair: ')."{$repaired}   already correct: {$already}");
        if ($dupes) {
            $this->warn("Businesses with more than one LIVE Stripe subscription: {$dupes} — cancel the extras in Stripe.");
        }
        if (! $apply && $repaired) {
            $this->comment('Re-run with --apply to write these repairs.');
        }

        return self::SUCCESS;
    }
}
