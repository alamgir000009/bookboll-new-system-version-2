<?php

namespace App\Support;

use App\Models\Listing;

/**
 * Listings whose own-site channel stopped working, and what the owner may do.
 *
 * Payment on arrival is no longer an own-site method. A listing that offered
 * only cash on the provider's own site therefore takes no online bookings at
 * all — the storefront says so, and BookingService refuses the request however
 * it arrives.
 *
 * The document is explicit about what must NOT happen next: "Provide an owner
 * review path to select a supported method; do not silently enable deposits on
 * the owner's behalf." Enabling deposits for them would start charging their
 * customers a fee they never agreed to collect, so every change here needs a
 * method the owner named.
 *
 * This class decides who is affected. Deliberately in one place, because the
 * queue asks in SQL and the per-listing checks ask in PHP, and a queue that
 * disagrees with the check either hides a broken listing or offers to fix a
 * working one.
 */
class OwnSiteReview
{
    /** What an owner may choose. There is no default and no fourth option. */
    public const RESOLUTIONS = ['deposit', 'online', 'hide'];

    /**
     * The flag spellings that count as enabled.
     *
     * Laravel writes JSON booleans; rows saved by the older dashboard carry
     * "1"/"0" strings. Anything else — absent, null, "", "false" — is not an
     * enabled method, which is the same answer PHP's (bool) cast gives for the
     * values that actually occur.
     */
    public const TRUTHY_JSON = ['true', '1'];

    /** Is this listing's own-site channel offered but unusable? */
    public static function needsReview(Listing $listing): bool
    {
        // A listing hidden from the provider's own site has no own-site channel
        // to fix. Null means never set, which is offered.
        if ($listing->show_on_provider_site === false) {
            return false;
        }

        $po = is_array($listing->payment_options) ? $listing->payment_options : [];

        return ! self::flag($po, 'site_deposit_enabled') && ! self::flag($po, 'site_online_enabled');
    }

    /**
     * The same question in SQL, so the queue can be counted and paged without
     * loading every listing a platform admin can see.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function scopeNeedsReview($query)
    {
        $truthy = self::TRUTHY_JSON;

        return $query
            ->where(fn ($q) => $q->where('show_on_provider_site', '!=', false)
                ->orWhereNull('show_on_provider_site'))
            ->whereNotIn(self::jsonFlagSql('site_deposit_enabled'), $truthy)
            ->whereNotIn(self::jsonFlagSql('site_online_enabled'), $truthy);
    }

    /**
     * A JSON flag as a comparable string, with absent read as ''.
     *
     * COALESCE matters: without it an absent key is NULL, and `NULL NOT IN
     * (...)` is NULL rather than true — so the listings most in need of review,
     * the ones with no own-site keys at all, would be the ones left out.
     */
    protected static function jsonFlagSql(string $key): \Illuminate\Database\Query\Expression
    {
        return \Illuminate\Support\Facades\DB::raw(
            "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(listings.payment_options, '$.\"".$key."\"')), '')"
        );
    }

    protected static function flag(array $po, string $key): bool
    {
        $value = $po[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), self::TRUTHY_JSON, true);
    }

    /**
     * Why this listing is in the queue, in the owner's terms.
     *
     * A legacy cash-only row and a listing somebody switched everything off on
     * are the same problem with different histories, and the owner is told
     * which one they are looking at.
     */
    public static function reason(Listing $listing): string
    {
        $po = is_array($listing->payment_options) ? $listing->payment_options : [];

        return self::flag($po, 'site_cash_enabled') || self::flag($po, 'cash_on_arrival')
            ? 'legacy_cash_only'
            : 'no_online_method';
    }

    public static function isResolution(?string $method): bool
    {
        return is_string($method) && in_array($method, self::RESOLUTIONS, true);
    }
}
