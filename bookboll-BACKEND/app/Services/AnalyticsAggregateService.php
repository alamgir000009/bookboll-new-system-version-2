<?php

namespace App\Services;

use App\Models\Booking;
use App\Support\AccessScope;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Activity totals computed by the DATABASE, across every authorised record.
 *
 * The existing endpoint hands the browser up to 5,000 raw rows and lets it add
 * them up. That cannot answer "how many bookings in total" — only "how many in
 * the 5,000 most recent" — and the answer silently becomes wrong the moment a
 * venue passes that mark. readmeAlamgir is explicit that raising the cap to
 * another arbitrary number is not the fix.
 *
 * So this aggregates server-side and reports its own COVERAGE: whether it
 * counted everything that matched, how many that was, and why not if not. A
 * number with no coverage statement is how a partial total gets mistaken for a
 * complete one.
 *
 * The raw-array endpoint is untouched. It stays as the activity view it always
 * was; this is what the finance-shaped cards read.
 */
class AnalyticsAggregateService
{
    public const AGGREGATES_VERSION = 1;

    /** Bookings that represent a real marketplace sale. */
    protected const SALE_STATUSES = ['confirmed', 'completed'];

    public function aggregates(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return sendError('Unauthenticated.', [], 401);
        }

        $filters = $this->filters($request);
        if (isset($filters['error'])) {
            return sendError($filters['error'], ['filters' => [$filters['error']]], 422);
        }

        $venueIds = AccessScope::allowedVenueIds($user);   // null = unrestricted

        // One scoped query, reused for every aggregate, so the cards, the
        // series and any export cannot disagree about what they counted.
        $base = fn () => $this->scopedQuery($venueIds, $filters);

        $matched = (clone $base())->count();

        $eligible = $this->eligible($base());
        $eligibleCount = (clone $eligible)->distinct()->count('bookings.id');

        // Distinct customers ON THOSE bookings — the same numerator and
        // denominator the frequency card divides, so the division matches.
        $customerCount = (clone $eligible)->whereNotNull('bookings.user_id')
            ->distinct()->count('bookings.user_id');

        $repeat = $this->repeatCustomers($base());

        return sendSuccess('Analytics aggregates', [
            'aggregates_version' => self::AGGREGATES_VERSION,
            'as_of'              => CarbonImmutable::now()->utc()->toIso8601String(),
            // Echoed so a stale response for different filters can be rejected
            // rather than painted over the current selection.
            'filters'            => $filters,
            'coverage'           => [
                // Every matching row was counted, because the database counted
                // them. There is no page to fall off the end of.
                'status'         => 'complete',
                'matched'        => $matched,
                'included'       => $matched,
                'missing_reason' => null,
            ],
            'sales'    => $this->salesByCurrency($eligible),
            'bookings' => [
                'eligible'          => $eligibleCount,
                'customers'         => $customerCount,
                // Null rather than zero when there is nothing to divide:
                // "no data" and "zero bookings each" are different claims.
                'per_customer'      => $customerCount > 0
                    ? round($eligibleCount / $customerCount, 2)
                    : null,
                'repeat_customers'  => $repeat,
                'repeat_pct'        => $customerCount > 0
                    ? round($repeat / $customerCount * 100, 1)
                    : null,
            ],
            'series' => $this->series($base(), $filters),
        ]);
    }

    /**
     * Bookings that count as a sale.
     *
     * Each exclusion is one the document names. A provider reserving their own
     * court was never a marketplace sale; a cancelled or refunded booking is
     * not revenue; a booking with no resolvable customer cannot be counted
     * toward a per-customer figure.
     */
    protected function eligible($query)
    {
        return $query
            ->whereIn('bookings.status', self::SALE_STATUSES)
            ->whereNull('bookings.refunded_at')
            ->where(fn ($q) => $q
                ->whereNull('bookings.payment_method')
                ->orWhere('bookings.payment_method', '!=', 'payment_skipped'))
            ->where(fn ($q) => $q
                ->whereNull('bookings.payment_status')
                ->orWhereNotIn('bookings.payment_status', ['payment_skipped', 'cancelled']));
    }

    /**
     * Customers with more than one eligible booking in the period.
     *
     * Counted from DISTINCT booking ids, so a booking carrying two payment rows
     * does not look like two bookings — the document's "duplicate payments do
     * not multiply booking frequency".
     */
    protected function repeatCustomers($query): int
    {
        $sub = $this->eligible($query)
            ->whereNotNull('bookings.user_id')
            ->select('bookings.user_id')
            ->selectRaw('COUNT(DISTINCT bookings.id) as n')
            ->groupBy('bookings.user_id');

        return DB::query()->fromSub($sub, 't')->where('n', '>', 1)->count();
    }

    /**
     * Gross sales per currency.
     *
     * Never summed across currencies: adding QAR to PKR produces a number that
     * describes nothing. A booking with no currency recorded is reported under
     * its own label rather than folded into a real one.
     */
    protected function salesByCurrency($eligible): array
    {
        $rows = (clone $eligible)
            ->selectRaw("COALESCE(NULLIF(bookings.currency, ''), '?') as code")
            ->selectRaw('SUM(bookings.total_price) as gross')
            ->selectRaw('COUNT(DISTINCT bookings.id) as n')
            ->groupBy('code')
            ->get();

        return $rows->map(fn ($r) => [
            'currency' => $r->code === '?' ? null : strtoupper($r->code),
            'gross'    => Money::toMajor(
                Money::toMinor((float) $r->gross, $r->code === '?' ? '' : $r->code),
                $r->code === '?' ? '' : $r->code
            ),
            'bookings' => (int) $r->n,
        ])->values()->all();
    }

    /** Eligible bookings per day, for the chart. */
    protected function series($query, array $filters): array
    {
        $rows = $this->eligible($query)
            ->selectRaw('bookings.date as bucket')
            ->selectRaw('COUNT(DISTINCT bookings.id) as bookings')
            ->selectRaw('COUNT(DISTINCT bookings.user_id) as customers')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->limit(400)
            ->get();

        return $rows->map(fn ($r) => [
            'bucket'    => $r->bucket instanceof \Carbon\CarbonInterface
                ? $r->bucket->format('Y-m-d') : (string) $r->bucket,
            'bookings'  => (int) $r->bookings,
            'customers' => (int) $r->customers,
        ])->values()->all();
    }

    /** The scoped, filtered booking query every aggregate starts from. */
    protected function scopedQuery(?array $venueIds, array $f)
    {
        $q = Booking::query()->when($venueIds !== null, fn ($x) => $x->whereIn('bookings.venue_id', $venueIds ?: [0]));

        if ($f['from_date']) {
            $q->whereDate('bookings.date', '>=', $f['from_date']);
        }
        if ($f['to_date']) {
            // Inclusive, because the picker shows both days as selected.
            $q->whereDate('bookings.date', '<=', $f['to_date']);
        }
        if ($f['venue_id']) {
            $q->where('bookings.venue_id', $f['venue_id']);
        }
        if ($f['listing_id']) {
            $q->where('bookings.listing_id', $f['listing_id']);
        }
        if ($f['status']) {
            $q->where('bookings.status', $f['status']);
        }
        if ($f['payment_status']) {
            $q->where('bookings.payment_status', $f['payment_status']);
        }
        if ($f['payment_method']) {
            $q->where('bookings.payment_method', $f['payment_method']);
        }
        if ($f['channel']) {
            $q->where('bookings.origin', $f['channel']);
        }
        if ($f['country_id'] || $f['city_id']) {
            $q->whereHas('venue', function ($v) use ($f) {
                if ($f['country_id']) {
                    $v->where('country_id', $f['country_id']);
                }
                if ($f['city_id']) {
                    $v->where('city_id', $f['city_id']);
                }
            });
        }
        if ($f['search'] !== '') {
            $term = '%'.$f['search'].'%';
            $q->where(fn ($w) => $w
                ->where('bookings.booking_id', 'like', $term)
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term)));
        }

        return $q;
    }

    /**
     * Normalised filters, or an error.
     *
     * A reversed or unparseable range is refused rather than quietly ignored —
     * silently widening a range the user narrowed is how a total comes back
     * larger than the question asked for.
     *
     * @return array<string, mixed>
     */
    protected function filters(Request $request): array
    {
        $date = function (?string $v) {
            if (! $v) {
                return null;
            }

            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $v)->toDateString();
            } catch (\Throwable $e) {
                return false;
            }
        };

        $from = $date($request->query('from_date'));
        $to   = $date($request->query('to_date'));

        if ($from === false || $to === false) {
            return ['error' => 'Use YYYY-MM-DD dates.'];
        }
        if ($from && $to && $from > $to) {
            return ['error' => 'The end date must be on or after the start date.'];
        }

        return [
            'from_date'      => $from,
            'to_date'        => $to,
            'timezone'       => (string) ($request->query('timezone') ?: config('app.timezone', 'UTC')),
            'country_id'     => $request->query('country_id') ? (int) $request->query('country_id') : null,
            'city_id'        => $request->query('city_id') ? (int) $request->query('city_id') : null,
            'venue_id'       => $request->query('venue_id') ? (int) $request->query('venue_id') : null,
            'listing_id'     => $request->query('listing_id') ? (int) $request->query('listing_id') : null,
            'status'         => $request->query('status') ?: null,
            'payment_status' => $request->query('payment_status') ?: null,
            'payment_method' => $request->query('payment_method') ?: null,
            'channel'        => $request->query('channel') ?: null,
            'search'         => trim((string) $request->query('search', '')),
        ];
    }
}
