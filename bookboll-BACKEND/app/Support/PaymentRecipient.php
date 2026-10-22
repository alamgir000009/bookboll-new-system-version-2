<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;

/**
 * The `payments.recipient` label — who the money went to, in one place.
 *
 * This column is a free-text label, not a foreign key, and the payments
 * dashboard reads it for three things: the "to Bookboll" / "to provider" split
 * on the stats card, the recipient filter, and the label on each row. Nothing
 * that moves money reads it, so it is a reporting field — but an empty one is
 * not neutral. PaymentService counts `recipient IS NULL` as Bookboll's, so a
 * provider receipt left blank is reported as platform revenue.
 *
 * That default is right for Bookboll's own venues, where Bookboll's staff did
 * take the cash. It stops being right the moment a venue belongs to a provider,
 * which is exactly what a venue transfer does — hence this helper, so the
 * reconciliation and the live booking path spell the same business the same way
 * instead of each inventing a label.
 */
class PaymentRecipient
{
    /** Bookboll's own share, however it arrives. */
    public const PLATFORM = '@bookboll';

    /** A provider we could not name. Still non-null, so it reports as theirs. */
    public const PROVIDER_FALLBACK = '@provider';

    /**
     * The label for money a provider received.
     *
     * Username first, because that is the handle the dashboard's recipient
     * filter groups by and the one already in the data. The owner is a second
     * chance at a name, not a different subject: a business with no username
     * and no name is still that business's money, so the fallback is a provider
     * label rather than null.
     */
    public static function forBusiness(?Business $business, ?User $owner = null): string
    {
        $username = trim((string) ($business->username ?? ''));

        if ($username !== '') {
            return str_starts_with($username, '@') ? $username : '@'.$username;
        }

        foreach ([$business->name ?? null, $owner?->name] as $name) {
            $name = trim((string) $name);

            if ($name !== '') {
                return $name;
            }
        }

        return self::PROVIDER_FALLBACK;
    }
}
