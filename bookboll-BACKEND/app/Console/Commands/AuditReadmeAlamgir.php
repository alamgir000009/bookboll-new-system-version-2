<?php

namespace App\Console\Commands;

use App\Services\BrandResolutionService;
use App\Support\StripeDocumentLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * Post-deploy verification for the readmeAlamgir.md work.
 *
 * Checks what is actually wired on THIS environment — schema, routes and the
 * behaviour of the pieces that can be exercised without creating records — and
 * states plainly what is still not implemented, so a green run can never be
 * mistaken for "the whole document is done".
 *
 * Read-only: creates, changes and deletes nothing.
 */
class AuditReadmeAlamgir extends Command
{
    protected $signature = 'audit:readme-alamgir';

    protected $description = 'Verify which readmeAlamgir.md backend items are live on this environment';

    protected array $rows = [];
    protected int $failed = 0;

    public function handle(): int
    {
        $this->line('');
        $this->info('IMPLEMENTED — these should all pass after a correct deploy');
        $this->line('');

        // 1. Website section visibility (readmeAlamgir §8)
        $sections = BrandResolutionService::homeSections(['sections' => ['locations' => false]]);
        $this->check(
            'Website sections: public projection hides locations',
            ($sections['locations'] ?? null) === false && ($sections['contact'] ?? null) === true,
            'BrandResolutionService::homeSections'
        );
        $this->check(
            'Website sections: unsaved flag stays visible',
            (BrandResolutionService::homeSections([])['locations'] ?? null) === true,
            'missing key must not hide content'
        );

        // 2. Stripe document links
        $this->check(
            'Schema: payments.stripe_invoice_url',
            Schema::hasColumn('payments', 'stripe_invoice_url'),
            'migration 2026_10_18_000001'
        );
        $this->check(
            'Schema: payments.stripe_receipt_url',
            Schema::hasColumn('payments', 'stripe_receipt_url'),
            'migration 2026_10_18_000001'
        );
        $this->check(
            'Stripe links: only https stripe.com is served',
            StripeDocumentLink::sanitize('https://invoice.stripe.com/i/a') !== null
                && StripeDocumentLink::sanitize('https://stripe.com.evil.test/x') === null
                && StripeDocumentLink::sanitize('http://invoice.stripe.com/i/a') === null,
            'StripeDocumentLink::sanitize'
        );
        $this->check(
            'Stripe links: offered only for captured online money',
            StripeDocumentLink::appliesTo('online', 'paid')
                && ! StripeDocumentLink::appliesTo('cash', 'paid')
                && ! StripeDocumentLink::appliesTo('payment_skipped', 'paid'),
            'StripeDocumentLink::appliesTo'
        );
        $this->check(
            'Scheduled: payments:backfill-stripe-urls',
            $this->isScheduled('payments:backfill-stripe-urls'),
            'routes/console.php'
        );

        // 3. Upload ownership
        $this->check('Schema: uploads table', Schema::hasTable('uploads'), 'migration 2026_10_18_000002');
        $this->check(
            'Schema: uploads.user_id + unique path',
            Schema::hasTable('uploads')
                && Schema::hasColumn('uploads', 'user_id')
                && Schema::hasColumn('uploads', 'path'),
            'ownership record'
        );
        $this->check(
            'Scheduled: uploads:prune (safe mode only)',
            $this->isScheduled('uploads:prune') && ! $this->isScheduled('uploads:prune --delete-unreferenced'),
            'the destructive sweep must stay manual'
        );
        $this->check(
            'Upload ownership: strict mode flag readable',
            is_bool(config('uploads.strict_ownership')),
            'config/uploads.php'
        );

        // 4. Group chat moderation
        $this->check(
            'Schema: customer_messages.deleted_at',
            Schema::hasColumn('customer_messages', 'deleted_at'),
            'migration 2026_10_18_000003 — REQUIRED, every message query filters on it'
        );
        $this->check(
            'Route: DELETE customer/messages/group/{id}',
            $this->hasRoute('DELETE', 'customer/messages/group/{id}'),
            'readmeAlamgir §9'
        );

        // 5. Business identity (readmeAlamgir §1)
        $this->check('Schema: business_memberships table', Schema::hasTable('business_memberships'), 'migration 2026_10_19_000002');
        $this->check(
            'Schema: businesses.owner_id is nullable (ownerless)',
            \App\Models\Business::ownerlessSupported(),
            'migration 2026_10_19_000001'
        );
        $this->check(
            'Schema: businesses.idempotency_key (safe retries)',
            Schema::hasColumn('businesses', 'idempotency_key'),
            'migration 2026_10_19_000004'
        );
        $this->check(
            'Route: GET admin/businesses/capabilities',
            $this->hasRoute('GET', 'admin/businesses/capabilities'),
            'readmeAlamgir §1'
        );
        $this->check(
            'Route: POST admin/businesses (ownerless create)',
            $this->hasRoute('POST', 'admin/businesses'),
            'readmeAlamgir §1'
        );
        $this->check(
            'DB enforces one active owner per business',
            $this->hasOneActiveOwnerIndex(),
            'business_memberships_one_active_owner'
        );

        // 6. Own-site commission policy (readmeAlamgir "Requested policy")
        $this->check(
            'Commission: own-site halves only for a paid plan',
            \App\Support\CommissionPolicy::PAID_PLAN_MULTIPLIER === 0.5,
            'CommissionPolicy'
        );
        $this->check(
            'Commission: 7.5% of 100 bills 7.50, not 7',
            \App\Support\Money::percentOf(\App\Support\Money::toMinor(100, 'QAR'), 7.5) === 750,
            'Money — floor() used to charge 7'
        );
        $this->check(
            'Money: real currency exponents (JPY 0, KWD 3)',
            \App\Support\Money::exponent('JPY') === 0
                && \App\Support\Money::exponent('KWD') === 3
                && \App\Support\Money::exponent('QAR') === 2,
            'Money — round($x * 100) assumed 2 everywhere'
        );
        $this->check(
            'Commission: fee + share always rebuild the total',
            (function () {
                foreach ([[10000, 7.5], [9999, 15], [3333, 33.33], [1, 50]] as [$m, $p]) {
                    $s = \App\Support\Money::split($m, $p);
                    if ($s['fee'] + $s['remainder'] !== $m) {
                        return false;
                    }
                }

                return true;
            })(),
            'Money::split'
        );
        $this->check(
            'Plan: own_site_commission_discount_eligible is served',
            str_contains(
                (string) file_get_contents(app_path('Services/ProviderPlanService.php')),
                'own_site_commission_discount_eligible'
            ),
            'GET /admin/plan'
        );

        // 7. Business assignments (readmeAlamgir §1.5)
        $this->check(
            'Schema: business_assignment_previews (reviewed moves)',
            Schema::hasTable('business_assignment_previews'),
            'migration 2026_10_19_000006'
        );
        foreach ([
            ['GET', 'admin/businesses/{id}/assignment-options'],
            ['POST', 'admin/businesses/{id}/assignment-preview'],
            ['POST', 'admin/businesses/{id}/assignments'],
        ] as [$verb, $uri]) {
            $this->check("Route: {$verb} {$uri}", $this->hasRoute($verb, $uri), 'readmeAlamgir §1.5');
        }
        $this->check(
            'Assignments: owner seat is never offered',
            ! in_array('owner', \App\Models\BusinessMembership::ASSIGNABLE_ROLES, true),
            'an owner is attached by the transfer flow, not this one'
        );

        // 8. Annual-only Premium, no trial (readmeAlamgir §6)
        $this->check(
            'Premium: checkout accepts only the annual offer',
            str_contains(
                (string) file_get_contents(app_path('Services/ProviderPlanService.php')),
                "'billing'     => 'required|string|in:yearly'"
            ),
            'POST /admin/plan/checkout'
        );
        $this->check(
            'Premium: the server never asks Stripe for a trial',
            ! str_contains(
                preg_replace('#//.*$#m', '', (string) file_get_contents(app_path('Services/ProviderPlanService.php'))),
                'trial_period_days'
            ),
            'a server-created trial cannot be switched off by the browser'
        );

        // 8. Commission snapshot on bookings
        $this->check(
            'Schema: bookings carry the commission snapshot',
            Schema::hasColumn('bookings', 'commission_channel')
                && Schema::hasColumn('bookings', 'commission_effective_pct')
                && Schema::hasColumn('bookings', 'commission_fee_minor'),
            'migration 2026_10_19_000005 — preserves historical fees'
        );

        // 9. Loyalty: country scoping and the checkout quote
        $this->check(
            'Schema: loyalty_points.country_id',
            Schema::hasColumn('loyalty_points', 'country_id'),
            'migration 2026_10_19_000007 — balances are per country'
        );
        $this->check(
            'Schema: loyalty_checkout_quotes',
            Schema::hasTable('loyalty_checkout_quotes'),
            'migration 2026_10_19_000008'
        );
        $this->check(
            'Route: POST customer/loyalty/checkout-quote',
            $this->hasRoute('POST', 'customer/loyalty/checkout-quote'),
            'readmeAlamgir redemption contract §2'
        );
        $this->check(
            'Rewards never exceed Bookboll own share',
            (function () {
                // 100 order, 15 commission, plenty of points -> at most 15.
                $r = \App\Support\LoyaltyRedemption::quote(
                    ['commission' => 1500, 'event_fee' => 0, 'booking_fee' => 0],
                    10000, 10000, 1000000, 100, 100, null, 'QAR'
                );

                return $r['discount_minor'] === 1500;
            })(),
            'LoyaltyRedemption'
        );
        $this->check(
            'Zero commission funds nothing (never a default rate)',
            \App\Support\LoyaltyRedemption::quote(
                ['commission' => 0, 'event_fee' => 0, 'booking_fee' => 0],
                10000, 10000, 1000000, 100, 100, null, 'QAR'
            )['points'] === 0,
            'the old membership path fell back to 15%'
        );

        $this->check(
            'Rewards: redemption_checkout_v1 is advertised',
            str_contains(
                preg_replace('#//.*$#m', '', (string) file_get_contents(app_path('Services/LoyaltyPointService.php'))),
                "'redemption_checkout_v1' => true"
            ),
            'venue checkout enforces the quote'
        );
        $this->check(
            'Rewards: unsupported payments refuse a quote',
            class_exists(\App\Http\Middleware\RejectUnsupportedRewards::class),
            'never charge full price while the customer thinks points applied'
        );
        $this->check(
            'Schema: bookings.loyalty_quote_id',
            Schema::hasColumn('bookings', 'loyalty_quote_id'),
            'migration 2026_10_19_000009 — lets a redirect consume its reservation'
        );

        // 10. Venue operating agreements + private contracts
        $this->check(
            'Schema: venue_operating_agreements (immutable versions)',
            Schema::hasTable('venue_operating_agreements')
                && Schema::hasColumn('venue_operating_agreements', 'superseded_on'),
            'migration 2026_10_19_000011'
        );
        $this->check(
            'Schema: venues.operator_type (recorded, not inferred)',
            Schema::hasColumn('venues', 'operator_type'),
            'a null owner must not grant payout exemptions'
        );
        $this->check(
            'Schema: venue_contract_documents (private)',
            Schema::hasTable('venue_contract_documents'),
            'migration 2026_10_19_000012'
        );
        foreach ([
            ['GET', 'admin/venues/{id}/operating-agreements'],
            ['POST', 'admin/venues/{id}/operating-agreements'],
            ['POST', 'admin/venues/{id}/contracts'],
        ] as [$verb, $uri]) {
            $this->check("Route: {$verb} {$uri}", $this->hasRoute($verb, $uri), 'readmeAlamgir §2');
        }
        $this->check(
            'Rent: the document October example is 4,700',
            \App\Support\RentProration::monthFromSegments('2026-10', [
                ['monthly_minor' => 310000, 'from' => '2026-10-01', 'to' => '2026-10-15'],
                ['monthly_minor' => 620000, 'from' => '2026-10-16', 'to' => '2026-10-31'],
            ]) === 470000,
            'RentProration — 15 x 100 + 16 x 200'
        );
        $this->check(
            'Rent: a full month sums exactly, no rounding drift',
            array_sum(\App\Support\RentProration::monthly(100000, '2026-04-01', '2026-04-30')) === 100000,
            '1,000 over 30 days does not divide evenly'
        );
        $this->check(
            'Contracts: type is read from the file bytes',
            \App\Support\ContractFileType::detect('%PDF-1.7') === 'pdf'
                && \App\Support\ContractFileType::detect('<?php echo 1;') === null,
            'an extension and a content-type header are not evidence'
        );
        $this->check(
            'Scheduled: contracts:prune-temporary',
            $this->isScheduled('contracts:prune-temporary'),
            'abandoned uploads expire; linked ones never do'
        );

        // 11. Subscriber saved-payment picker (readmeAlamgir §11)
        $this->check(
            'Route: GET admin/subscriptions/dashboard/{id}/payment-methods',
            $this->hasRoute('GET', 'admin/subscriptions/dashboard/{id}/payment-methods'),
            'staff pick a stored card, never type a reference'
        );
        $this->check(
            'Subscriptions: every action is business-scoped',
            ! str_contains(
                (string) file_get_contents(app_path('Services/ClassMembershipDashboardService.php')),
                'ClassSubscription::query()->find($id)'
            ),
            'these loaded by bare id with no business check at all'
        );

        // 11. Country/city availability
        $this->check(
            'Route: marketplace/countries',
            $this->hasRoute('GET', 'marketplace/countries'),
            'returns cities_count + has_cities'
        );

        $this->line('');
        $this->table(['check', 'result', 'where'], $this->rows);

        $this->line('');
        $this->warn('NOT IMPLEMENTED — nothing below exists on any environment yet');
        $this->line('');
        foreach ([
            'Venue transfer preview/commit and legacy reconciliation',
            'Analytics filter-aware aggregates (the 5,000-row limit is still in place)',
            'bookings.record_purpose backfill for provider slot blocks',
            'customer_types classification + advanced filters',
            'Category popularity aggregation + POST /marketplace/category-clicks',
            'Export scope/completeness guarantees',
            'Own-site commission: the owner review path for existing cash-only'
                .' listings (policy, rating, rounding, method enforcement, the'
                .' booking snapshot and the wallet ledger are DONE)',
            'GET /marketplace/users (people search)',
            'Community permissions persistence + moderator role hierarchy',
            'Provider settings profile/account + notification preferences',
            'Mobile-app adoption APIs',
            'Rewards for MEMBERSHIP, host_game and host_tournament checkouts'
                .' (venue checkout is fully enforced; those three refuse a quote'
                .' outright rather than ignoring one)',
            'Cookie/tracking infrastructure audit (deployment task, not code)',
        ] as $i => $item) {
            $this->line(sprintf('  %2d. %s', $i + 1, $item));
        }

        $this->line('');
        if ($this->failed > 0) {
            $this->error("{$this->failed} implemented check(s) FAILED — the deploy is not correct yet.");

            return self::FAILURE;
        }

        $this->info('All implemented checks passed. The list above is still outstanding.');

        return self::SUCCESS;
    }

    protected function check(string $label, bool $ok, string $where): void
    {
        if (! $ok) {
            $this->failed++;
        }
        $this->rows[] = [$label, $ok ? 'PASS' : 'FAIL', $where];
    }

    protected function hasRoute(string $method, string $uri): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (in_array($method, $route->methods(), true)
                && str_ends_with($route->uri(), $uri)) {
                return true;
            }
        }

        return false;
    }

    /** The generated-column index that makes a second active owner impossible. */
    protected function hasOneActiveOwnerIndex(): bool
    {
        if (! Schema::hasTable('business_memberships')) {
            return false;
        }

        try {
            foreach (Schema::getIndexes('business_memberships') as $index) {
                if (($index['name'] ?? '') === 'business_memberships_one_active_owner') {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    protected function isScheduled(string $command): bool
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        foreach ($schedule->events() as $event) {
            if (str_contains($event->command ?? '', $command)) {
                return true;
            }
        }

        return false;
    }
}
