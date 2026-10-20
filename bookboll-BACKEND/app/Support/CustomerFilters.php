<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Every filter the customers screen offers, applied where the rows are chosen.
 *
 * The admin's filter sheet collected fourteen choices — country, city, venue,
 * listing, program, sport, currency, loyalty tier, gender, tags, marketing
 * consent, joined range, spend range, booking-count range, last activity — and
 * the server applied three: search, status and customer type. The rest were
 * read by nobody. Pick a listing and the page did not change, so "customers of
 * this court" simply could not be answered, and a filtered export was the whole
 * list wearing a filename.
 *
 * Everything here is applied BEFORE sorting, pagination, counts and export, so
 * the page, its total, the KPIs and a select-all all describe the same set.
 * Activity-based filters (booked at, booked in, spent, last booked) only count
 * sales bookings the viewer is allowed to see, so a provider cannot learn which
 * of their customers also book somewhere else.
 */
class CustomerFilters
{
    /** Filters that were requested and applied, for the client to echo. */
    public static array $applied = [];

    public static function apply(Builder $query, Request $request, ?array $venueIds, ?array $programIds): Builder
    {
        self::$applied = [];

        // ── Who they are ──────────────────────────────────────────────────────
        if ($ids = self::ids($request, 'country_id')) {
            self::$applied['country_id'] = $ids;
            $query->whereIn('users.country_id', $ids);
        }

        if ($genders = self::list($request, 'gender')) {
            self::$applied['gender'] = $genders;
            $named = array_values(array_intersect($genders, ['male', 'female']));
            $other = in_array('other', $genders, true);
            $query->where(function ($q) use ($named, $other) {
                if ($named) {
                    $q->whereIn('users.gender', $named);
                }
                if ($other) {
                    // "Not specified": blank, or anything that is not one of the two.
                    $q->orWhereNull('users.gender')
                        ->orWhereNotIn('users.gender', ['male', 'female']);
                }
            });
        }

        if ($tier = trim((string) $request->input('tier', ''))) {
            self::$applied['tier'] = $tier;
            $query->whereHas('loyaltyTier', fn ($t) => $t->where('name', $tier));
        }

        if ($tags = self::list($request, 'tag')) {
            self::$applied['tag'] = $tags;
            $query->whereHas('tags', fn ($t) => $t->whereIn('name', $tags));
        }

        if ($consent = self::list($request, 'marketing')) {
            self::$applied['marketing'] = $consent;
            $query->where(function ($q) use ($consent) {
                foreach (['email', 'sms', 'whatsapp'] as $channel) {
                    if (in_array($channel, $consent, true)) {
                        $q->orWhere("users.marketing_{$channel}", true);
                    }
                }
                if (in_array('none', $consent, true)) {
                    $q->orWhere(fn ($n) => $n
                        ->where(fn ($e) => $e->whereNull('users.marketing_email')->orWhere('users.marketing_email', false))
                        ->where(fn ($s) => $s->whereNull('users.marketing_sms')->orWhere('users.marketing_sms', false))
                        ->where(fn ($w) => $w->whereNull('users.marketing_whatsapp')->orWhere('users.marketing_whatsapp', false)));
                }
            });
        }

        if ($from = self::date($request, 'joined_from')) {
            self::$applied['joined_from'] = $from->toDateString();
            $query->where('users.created_at', '>=', $from->startOfDay());
        }
        if ($to = self::date($request, 'joined_to')) {
            self::$applied['joined_to'] = $to->toDateString();
            $query->where('users.created_at', '<=', $to->endOfDay());
        }

        // ── What they have booked ─────────────────────────────────────────────
        // One EXISTS per filter, each over the sales bookings the viewer may see.
        // Separate EXISTS clauses, not one combined, so "booked at venue A" AND
        // "booked listing B" means both have happened, as a reader would expect.
        if ($ids = self::ids($request, 'listing_id')) {
            self::$applied['listing_id'] = $ids;
            $query->whereExists(fn ($b) => self::sales($b, $venueIds)->whereIn('bookings.listing_id', $ids));
        }

        if ($ids = self::ids($request, 'location_id')) {
            self::$applied['location_id'] = $ids;
            $query->whereExists(fn ($b) => self::sales($b, $venueIds)->whereIn('bookings.venue_id', $ids));
        }

        if ($ids = self::ids($request, 'city_id')) {
            self::$applied['city_id'] = $ids;
            $query->whereExists(fn ($b) => self::sales($b, $venueIds)
                ->join('venues', 'venues.id', '=', 'bookings.venue_id')
                ->whereIn('venues.city_id', $ids));
        }

        if ($sports = self::list($request, 'sport')) {
            self::$applied['sport'] = $sports;
            // The sheet lists the NAME the listing carries, which may be either the
            // legacy free-text column or the category it is linked to.
            $query->whereExists(fn ($b) => self::sales($b, $venueIds)
                ->join('listings', 'listings.id', '=', 'bookings.listing_id')
                ->leftJoin('sports_categories', 'sports_categories.id', '=', 'listings.sports_category_id')
                ->where(fn ($s) => $s
                    ->whereIn('listings.sport_category', $sports)
                    ->orWhereIn('sports_categories.name', $sports)));
        }

        if ($currencies = self::list($request, 'currency')) {
            self::$applied['currency'] = $currencies;
            $query->whereExists(fn ($b) => self::sales($b, $venueIds)->whereIn('bookings.currency', $currencies));
        }

        $spendMin = self::number($request, 'spend_min');
        $spendMax = self::number($request, 'spend_max');
        if ($spendMin !== null || $spendMax !== null) {
            self::$applied['spend'] = [$spendMin, $spendMax];
            // Spend is only comparable within one currency. With one currency
            // selected the sum is in that currency; otherwise it is the raw total,
            // which is what the screen shows next to the slider.
            $query->whereIn('users.id', function ($sub) use ($venueIds, $currencies, $spendMin, $spendMax) {
                $sub->select('bookings.user_id')->from('bookings');
                self::salesWhere($sub, $venueIds);
                if ($currencies) {
                    $sub->whereIn('bookings.currency', $currencies);
                }
                $sub->groupBy('bookings.user_id');
                if ($spendMin !== null) {
                    $sub->havingRaw('SUM(bookings.total_price) >= ?', [$spendMin]);
                }
                if ($spendMax !== null) {
                    $sub->havingRaw('SUM(bookings.total_price) <= ?', [$spendMax]);
                }
            });
        }

        $bkMin = self::number($request, 'bk_min');
        $bkMax = self::number($request, 'bk_max');
        if ($bkMin !== null || $bkMax !== null) {
            self::$applied['bookings'] = [$bkMin, $bkMax];
            $query->whereIn('users.id', function ($sub) use ($venueIds, $bkMin, $bkMax) {
                $sub->select('bookings.user_id')->from('bookings');
                self::salesWhere($sub, $venueIds);
                $sub->groupBy('bookings.user_id');
                if ($bkMin !== null) {
                    $sub->havingRaw('COUNT(*) >= ?', [(int) $bkMin]);
                }
                if ($bkMax !== null) {
                    $sub->havingRaw('COUNT(*) <= ?', [(int) $bkMax]);
                }
            });
        }

        // Driven by bookings, not logins: last_login_at is set on a handful of
        // accounts, so "active in the last 30 days" has to mean "booked".
        $activity = (string) $request->input('last_activity', '');
        if (in_array($activity, ['7d', '30d', '90d', 'inactive', 'never'], true)) {
            self::$applied['last_activity'] = $activity;
            $recent = fn (int $days) => fn ($b) => self::sales($b, $venueIds)->where('bookings.date', '>=', now()->subDays($days)->toDateString());
            match ($activity) {
                '7d'       => $query->whereExists($recent(7)),
                '30d'      => $query->whereExists($recent(30)),
                '90d'      => $query->whereExists($recent(90)),
                'inactive' => $query->whereExists(fn ($b) => self::sales($b, $venueIds))->whereNotExists($recent(90)),
                'never'    => $query->whereNotExists(fn ($b) => self::sales($b, $venueIds)),
            };
        }

        // ── Memberships ───────────────────────────────────────────────────────
        if ($ids = self::ids($request, 'program_id')) {
            self::$applied['program_id'] = $ids;
            $query->whereExists(fn ($s) => self::subscriptions($s, $programIds)->whereIn('class_subscriptions.program_id', $ids));
        }

        if ($statuses = self::list($request, 'sub_status')) {
            self::$applied['sub_status'] = $statuses;
            $query->where(function ($q) use ($statuses, $programIds) {
                if (in_array('active', $statuses, true)) {
                    $q->orWhereExists(fn ($s) => self::subscriptions($s, $programIds)
                        ->where('class_subscriptions.status', 'active')
                        ->whereNull('class_subscriptions.cancelled_at'));
                }
                if (in_array('in_trial', $statuses, true)) {
                    $q->orWhereExists(fn ($s) => self::subscriptions($s, $programIds)
                        ->where('class_subscriptions.trial_ends_at', '>', now())
                        ->whereNull('class_subscriptions.first_charge_at')
                        ->whereNull('class_subscriptions.cancelled_during_trial_at')
                        ->whereNull('class_subscriptions.cancelled_at'));
                }
                if (in_array('ex', $statuses, true)) {
                    $q->orWhereExists(fn ($s) => self::subscriptions($s, $programIds)
                        ->where(fn ($x) => $x
                            ->whereNotNull('class_subscriptions.cancelled_at')
                            ->orWhereIn('class_subscriptions.status', ['cancelled', 'expired', 'ended'])));
                }
                if (in_array('none', $statuses, true)) {
                    $q->orWhereNotExists(fn ($s) => self::subscriptions($s, $programIds));
                }
            });
        }

        return $query;
    }

    // ── Building blocks ───────────────────────────────────────────────────────

    /** A sales booking of this user that the viewer is allowed to see. */
    protected static function sales($q, ?array $venueIds)
    {
        $q->selectRaw('1')->from('bookings')->whereColumn('bookings.user_id', 'users.id');

        return self::salesWhere($q, $venueIds);
    }

    protected static function salesWhere($q, ?array $venueIds)
    {
        return $q->where('bookings.status', '!=', 'cancelled')
            ->whereNull('bookings.refunded_at')
            ->when($venueIds !== null, fn ($b) => $b->whereIn('bookings.venue_id', $venueIds ?: [0]))
            ->where(fn ($b) => BookingPurpose::scopeSales($b));
    }

    /**
     * A subscription of this user, matched the two ways a subscription names
     * its customer: by uuid, or by e-mail compared case-insensitively.
     */
    protected static function subscriptions($q, ?array $programIds)
    {
        return $q->selectRaw('1')->from('class_subscriptions')
            ->where(fn ($w) => $w
                ->whereColumn('class_subscriptions.customer_id', 'users.uuid')
                ->orWhereRaw('LOWER(class_subscriptions.customer_email) = LOWER(users.email)'))
            ->when($programIds !== null, fn ($s) => $s->whereIn('class_subscriptions.program_id', $programIds ?: [0]));
    }

    /** @return array<int,string> */
    protected static function list(Request $request, string $key): array
    {
        $v = $request->input($key);
        if (is_string($v)) {
            $v = $v === '' ? [] : explode(',', $v);
        }

        return is_array($v) ? array_values(array_filter(array_map(fn ($x) => trim((string) $x), $v), fn ($x) => $x !== '')) : [];
    }

    /** @return array<int,int> */
    protected static function ids(Request $request, string $key): array
    {
        return array_values(array_filter(array_map('intval', self::list($request, $key))));
    }

    protected static function number(Request $request, string $key): ?float
    {
        $v = $request->input($key);

        return ($v === null || $v === '' || ! is_numeric($v)) ? null : (float) $v;
    }

    protected static function date(Request $request, string $key): ?Carbon
    {
        $v = trim((string) $request->input($key, ''));
        if ($v === '') {
            return null;
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }
}
