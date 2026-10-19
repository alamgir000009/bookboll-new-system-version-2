<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which Bookboll products a customer has actually used.
 *
 * Derived from activity, never editable — these are separate from the manual
 * CRM tags a business writes by hand. A customer may hold any combination,
 * including all three.
 *
 * One rule governs the whole class: the predicate that FILTERS a list and the
 * predicate that puts a badge on a row are the same code. If they diverge, a
 * filtered page shows customers whose badges contradict the filter, and nobody
 * can tell which half is wrong.
 *
 * Everything is scoped to what the viewer may see. A customer's activity in a
 * business the viewer has no access to must not appear as a type here —
 * otherwise the badge leaks the existence of that activity.
 */
class CustomerTypes
{
    public const BOOKING    = 'booking';
    public const MEMBERSHIP = 'membership';
    public const EVENT      = 'event';

    public const ALL = [self::BOOKING, self::MEMBERSHIP, self::EVENT];

    public const MATCH_ANY = 'any';
    public const MATCH_ALL = 'all';

    /** The filter contract the dashboard echoes back. */
    public const FILTER_VERSION = 1;

    /**
     * A genuine venue booking.
     *
     * Slot blocks are excluded through record_purpose, so a provider's own
     * reservation never makes its holder look like a marketplace customer —
     * which the document calls out by name.
     *
     * @param  array<int,int>|null  $venueIds  null = unrestricted
     */
    public static function bookingExists($q, ?array $venueIds)
    {
        return $q->selectRaw('1')->from('bookings')
            ->whereColumn('bookings.user_id', 'users.id')
            ->where('bookings.status', '!=', 'cancelled')
            ->whereNull('bookings.refunded_at')
            ->when($venueIds !== null, fn ($b) => $b->whereIn('bookings.venue_id', $venueIds ?: [0]))
            ->where(fn ($b) => BookingPurpose::scopeSales($b));
    }

    /**
     * An activated membership, including one that has since ended.
     *
     * Activation is the test, not current status: a completed or expired
     * enrolment still makes someone a membership customer. An application
     * abandoned or cancelled before it ever activated does not.
     *
     * Joined on users.uuid, because that is the identity subscriptions carry.
     *
     * @param  array<int,int>|null  $programIds
     */
    public static function membershipExists($q, ?array $programIds)
    {
        return $q->selectRaw('1')->from('class_subscriptions')
            ->whereColumn('class_subscriptions.customer_id', 'users.uuid')
            ->when($programIds !== null, fn ($s) => $s->whereIn('class_subscriptions.program_id', $programIds ?: [0]))
            ->where(fn ($s) => $s
                ->whereNotNull('class_subscriptions.started_at')
                ->orWhereNotNull('class_subscriptions.first_charge_at'))
            // Cancelled during a trial with no charge ever taken never activated.
            ->where(fn ($s) => $s
                ->whereNull('class_subscriptions.cancelled_during_trial_at')
                ->orWhereNotNull('class_subscriptions.first_charge_at'));
    }

    /**
     * Genuine participation in someone's event.
     *
     * Accepted joins only — a pending, rejected or cancelled request is not
     * participation. Hosting an event is deliberately NOT included: running one
     * is a different relationship from attending one.
     *
     * @param  array<int,int>|null  $venueIds
     */
    public static function eventExists($q, ?array $venueIds)
    {
        return $q->selectRaw('1')->from('game_join_requests')
            ->join('hosted_games', 'hosted_games.id', '=', 'game_join_requests.game_id')
            ->whereColumn('game_join_requests.joiner_customer_id', 'users.uuid')
            ->where('game_join_requests.status', 'accepted')
            ->when($venueIds !== null, fn ($g) => $g->whereIn('hosted_games.location_id', $venueIds ?: [0]));
    }

    /**
     * Restrict a users query to customers holding the selected types.
     *
     * `any` is OR — a customer of at least one of these products.
     * `all` is AND — a customer of every one of them.
     *
     * @param  array<int,string>  $types
     */
    public static function applyFilter($query, array $types, string $match, ?array $venueIds, ?array $programIds)
    {
        $types = array_values(array_intersect($types, self::ALL));
        if (! $types) {
            return $query;   // no selection means unfiltered
        }

        $predicate = fn (string $type) => match ($type) {
            self::BOOKING    => fn ($q) => self::bookingExists($q, $venueIds),
            self::MEMBERSHIP => fn ($q) => self::membershipExists($q, $programIds),
            self::EVENT      => fn ($q) => self::eventExists($q, $venueIds),
        };

        if ($match === self::MATCH_ALL) {
            foreach ($types as $type) {
                $query->whereExists($predicate($type));
            }

            return $query;
        }

        return $query->where(function ($q) use ($types, $predicate) {
            foreach ($types as $type) {
                $q->orWhereExists($predicate($type));
            }
        });
    }

    /**
     * The types held by each of these customers, within the viewer's scope.
     *
     * Computed for the whole page in three queries rather than three per row.
     * A customer with no qualifying activity gets an empty array — which is an
     * authoritative "none", distinct from the field being absent.
     *
     * @param  array<int,int>  $userIds
     * @return array<int, array<int,string>>
     */
    public static function forUsers(array $userIds, ?array $venueIds, ?array $programIds): array
    {
        $out = array_fill_keys($userIds, []);
        if (! $userIds) {
            return $out;
        }

        $sets = [
            self::BOOKING => DB::table('users')->whereIn('users.id', $userIds)
                ->whereExists(fn ($q) => self::bookingExists($q, $venueIds))->pluck('users.id'),
            self::MEMBERSHIP => DB::table('users')->whereIn('users.id', $userIds)
                ->whereExists(fn ($q) => self::membershipExists($q, $programIds))->pluck('users.id'),
            self::EVENT => DB::table('users')->whereIn('users.id', $userIds)
                ->whereExists(fn ($q) => self::eventExists($q, $venueIds))->pluck('users.id'),
        ];

        foreach ($sets as $type => $ids) {
            foreach ($ids as $id) {
                $out[(int) $id][] = $type;
            }
        }

        return $out;
    }

    /**
     * Read the filter off a request, or explain why it cannot be honoured.
     *
     * An unknown type is refused rather than ignored: quietly dropping it would
     * return an unfiltered page to someone who asked a narrower question.
     *
     * @return array{types: array<int,string>, match: string, error?: string}
     */
    public static function fromRequest($request): array
    {
        $raw = $request->input('customer_types', []);
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        $raw = array_values(array_filter(array_map('trim', (array) $raw)));

        $unknown = array_diff($raw, self::ALL);
        if ($unknown) {
            return ['types' => [], 'match' => self::MATCH_ANY,
                'error' => 'Unknown customer type: '.implode(', ', $unknown)];
        }

        $match = strtolower((string) $request->input('customer_types_match', self::MATCH_ANY));
        if (! in_array($match, [self::MATCH_ANY, self::MATCH_ALL], true)) {
            return ['types' => [], 'match' => self::MATCH_ANY,
                'error' => 'customer_types_match must be any or all.'];
        }

        return ['types' => array_values(array_unique($raw)), 'match' => $match];
    }
}
