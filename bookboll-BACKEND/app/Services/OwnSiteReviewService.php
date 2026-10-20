<?php

namespace App\Services;

use App\Models\Listing;
use App\Support\AccessScope;
use App\Support\CommissionPolicy;
use App\Support\Money;
use App\Support\OwnSiteReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The owner review path for own-site listings that can no longer be booked.
 *
 * Payment on arrival is gone from the own-site channel, so a listing that
 * offered only cash there now takes nothing. The owner has to pick what
 * replaces it — and the document is explicit that the server must not pick for
 * them: "do not silently enable deposits on the owner's behalf."
 *
 * So there is no default, no auto-fix and no "apply the obvious choice". The
 * queue says what is broken and what the options cost; applying one takes a
 * method the owner named.
 */
class OwnSiteReviewService
{
    /**
     * The listings waiting for a decision.
     *
     * Counted before paging and reported with a coverage status, so a dashboard
     * cannot show "3 listings need attention" when it means "3 on this page".
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $query = fn () => OwnSiteReview::scopeNeedsReview(
            AccessScope::applyVenue(Listing::query(), 'venue_id', $user)
        );

        $total = $query()->count('listings.id');

        $page = $query()
            ->with(['venue:id,name,country_id,owner_user_id'])
            ->orderBy('listings.id')
            ->paginate($perPage, ['listings.id', 'listings.name', 'listings.venue_id', 'listings.payment_options', 'listings.base_price', 'listings.show_on_marketplace'])
            ->withQueryString();

        $rows = collect($page->items())->map(fn (Listing $listing) => $this->card($listing))->all();

        return sendSuccess('Own-site review fetched', [
            'listings' => $rows,
            'total'    => $total,
            'coverage' => ['status' => 'complete', 'total' => $total],
            // Whose listings these are. A platform admin sees every provider's,
            // which is useful to know about and NOT theirs to decide on — the
            // dashboard changes its wording and drops the buttons on this.
            'viewer'   => [
                'scope'     => $this->actorScope($user),
                'can_apply' => $this->mayApply($user),
            ],
            // Named here so the dashboard offers exactly these and nothing else.
            'resolutions' => OwnSiteReview::RESOLUTIONS,
            'pagination'  => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Apply the owner's choice to the listings they named.
     *
     * Both the method and the listings are required. Nothing is inferred: an
     * empty list changes nothing, and a missing method is a refusal rather than
     * a guess.
     */
    public function apply(Request $request)
    {
        $user = $request->user();

        if (! $this->mayApply($user)) {
            // The whole point of this path is that the OWNER chooses. A platform
            // admin applying a method here would be deciding, for dozens of
            // businesses at once, to start charging their customers a deposit —
            // which is the thing the document forbids, just with a human
            // clicking it. Support can still fix one listing in its editor.
            return sendError(
                'Only the business can choose how its own website takes payment. Open the listing to change one.',
                [],
                403
            );
        }

        $data = $request->validate([
            'method'       => 'required|string|in:'.implode(',', OwnSiteReview::RESOLUTIONS),
            'listing_ids'  => 'required|array|min:1|max:200',
            'listing_ids.*' => 'required|integer',
        ]);

        $method = $data['method'];
        $ids = array_values(array_unique(array_map('intval', $data['listing_ids'])));

        // Scoped to the venues this user may touch. An id from elsewhere is not
        // found rather than silently applied.
        $listings = AccessScope::applyVenue(Listing::query(), 'venue_id', $user)
            ->whereIn('listings.id', $ids)
            ->with('venue')
            ->get()
            ->keyBy('id');

        $applied = [];
        $refused = [];

        foreach ($ids as $id) {
            $listing = $listings->get($id);

            if (! $listing) {
                $refused[] = ['listing_id' => $id, 'reason' => 'not_found'];

                continue;
            }

            if (! OwnSiteReview::needsReview($listing)) {
                // Already decided, by this owner or another device. Re-applying
                // would overwrite a working configuration with a bulk choice.
                $refused[] = ['listing_id' => $id, 'reason' => 'already_resolved', 'name' => $listing->name];

                continue;
            }

            $error = $this->methodUnavailable($listing, $method);
            if ($error) {
                $refused[] = ['listing_id' => $id, 'reason' => $error, 'name' => $listing->name];

                continue;
            }

            DB::transaction(fn () => $this->applyTo($listing, $method));

            $applied[] = ['listing_id' => $id, 'name' => $listing->name, 'method' => $method];
        }

        return sendSuccess(
            $applied ? 'Own-site booking updated' : 'Nothing was changed',
            [
                // Both halves, always. A bulk apply that quietly skips some
                // listings leaves an owner believing they are all fixed.
                'applied'   => $applied,
                'refused'   => $refused,
                'remaining' => OwnSiteReview::scopeNeedsReview(
                    AccessScope::applyVenue(Listing::query(), 'venue_id', $user)
                )->count('listings.id'),
            ]
        );
    }

    /**
     * Whose listings the queue is showing.
     *
     * A provider sees their own. Platform staff see every business's, because
     * AccessScope leaves them unscoped — worth knowing as an operational
     * signal, but not the same thing as owning the decision.
     */
    protected function actorScope(?\App\Models\User $user): string
    {
        return AccessScope::isProviderScoped($user) ? 'business' : 'platform';
    }

    /** Only the business that owns the listings may change how they charge. */
    protected function mayApply(?\App\Models\User $user): bool
    {
        return $this->actorScope($user) === 'business';
    }

    /**
     * Why a method cannot be used here, or null.
     *
     * The same conditions BookingService enforces, asked before the owner
     * commits — offering a method that would then refuse every booking is
     * worse than saying so now.
     */
    protected function methodUnavailable(Listing $listing, string $method): ?string
    {
        if ($method !== 'online') {
            return null;
        }

        return app(BookingWalletService::class)->venueHasActivePayout($listing->venue)
            ? null
            : 'payouts_not_connected';
    }

    /**
     * Write the decision.
     *
     * Each resolution sets exactly one thing. `hide` removes the own-site
     * channel and leaves marketplace visibility alone: a provider who does not
     * want online bookings on their own site may still sell on Bookboll.
     */
    protected function applyTo(Listing $listing, string $method): void
    {
        $po = is_array($listing->payment_options) ? $listing->payment_options : [];

        if ($method === 'hide') {
            $listing->show_on_provider_site = false;
            $listing->save();

            return;
        }

        $po['site_deposit_enabled'] = $method === 'deposit';
        $po['site_online_enabled']  = $method === 'online';

        // Retired, and never written true again. Cleared here so the row stops
        // claiming a method that no longer exists.
        $po['site_cash_enabled'] = false;

        // Not the authority for anything — the fee is derived at booking time by
        // CommissionPolicy. Recorded so the dashboard can show what the owner
        // was looking at when they chose, and refreshed here rather than left
        // at a rate from a plan they may no longer be on.
        $po['site_deposit_percentage'] = CommissionPolicy::effectiveRatePct($listing, CommissionPolicy::CHANNEL_SITE);

        $listing->payment_options = $po;
        $listing->show_on_provider_site = true;
        $listing->save();
    }

    /**
     * One queue row: what is wrong, and what each choice would mean.
     *
     * The deposit figure is a preview computed now from the business's current
     * plan, not a stored number — an owner who upgrades sees the new rate
     * without anyone re-saving their listings.
     */
    protected function card(Listing $listing): array
    {
        $currency = CommissionPolicy::currencyFor($listing);
        $total = Money::toMinor((float) ($listing->base_price ?? 0), $currency);
        $quote = CommissionPolicy::quote($listing, CommissionPolicy::CHANNEL_SITE, $total, $currency, 'deposit');
        $payoutsReady = app(BookingWalletService::class)->venueHasActivePayout($listing->venue);

        return [
            'id'         => $listing->id,
            'name'       => $listing->name ?: 'Listing',
            'venue_id'   => $listing->venue_id,
            'venue_name' => $listing->venue?->name ?: '',
            'reason'     => OwnSiteReview::reason($listing),
            // Still on Bookboll, or not selling anywhere at all — the owner
            // needs to know which, because it changes how urgent this is.
            'on_marketplace' => $listing->show_on_marketplace !== false,
            'preview' => [
                'currency'           => $quote['currency'],
                'base_price'         => Money::toMajor($quote['total_minor'], $quote['currency']),
                'standard_rate_pct'  => $quote['standard_rate_pct'],
                'effective_rate_pct' => $quote['effective_rate_pct'],
                'plan_eligible'      => $quote['plan_eligible'],
                'deposit_due_now'    => Money::toMajor($quote['due_now_minor'], $quote['currency']),
                'arrival_balance'    => Money::toMajor($quote['arrival_balance_minor'], $quote['currency']),
            ],
            // Deposit never needs payouts; full online always does. Said per
            // listing because payouts are country-scoped to its venue.
            'available' => [
                'deposit' => true,
                'online'  => $payoutsReady,
                'hide'    => true,
            ],
            'online_blocked_reason' => $payoutsReady ? null : 'payouts_not_connected',
        ];
    }
}
