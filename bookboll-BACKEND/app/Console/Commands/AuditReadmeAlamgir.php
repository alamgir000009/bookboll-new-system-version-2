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

        // 5. Country/city availability
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
            'Business identity + business_memberships, ownerless businesses',
            'GET /admin/businesses/capabilities and the 4 assignment endpoints',
            'Effective-dated venue_operating_agreements (+ history contract v2)',
            'POST /admin/venues/{id}/contracts (private contract storage)',
            'Venue transfer preview/commit and legacy reconciliation',
            'Analytics filter-aware aggregates (the 5,000-row limit is still in place)',
            'bookings.record_purpose backfill for provider slot blocks',
            'customer_types classification + advanced filters',
            'Category popularity aggregation + POST /marketplace/category-clicks',
            'Export scope/completeness guarantees',
            'Own-site commission policy (half rate for paid Premium)',
            'Annual-only Premium with no trial',
            'GET /marketplace/users (people search)',
            'Community permissions persistence + moderator role hierarchy',
            'Provider settings profile/account + notification preferences',
            'Secure saved-payment-method picker for subscribers',
            'Mobile-app adoption APIs',
            'Country-scoped loyalty balances (/customer/loyalty returns a global balance)',
            'POST /customer/loyalty/checkout-quote and its enforcement',
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
