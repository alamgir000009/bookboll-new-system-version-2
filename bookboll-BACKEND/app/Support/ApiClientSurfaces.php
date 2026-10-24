<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Which front-end a request came from, per the app's own `from` header.
 *
 * The header is this project's existing convention — isRequestFromAdminSurface(),
 * ListingService and VenueService all branch on it, and UserService uses it to
 * decide which token name an admin login gets. Recording it against the token
 * answers something the token name cannot: every customer login is named
 * 'bookboll-customer-login-api-token' whether it came from the website or the
 * phone, and only this header tells the two apart.
 *
 * IMPORTANT: the client chooses what to send here, and nothing verifies it. Read
 * it as a label the app applied to itself, not as proof of where a session is.
 * The IP address is the part of a session that cannot be forged.
 *
 * Values are defined in app/helpers.php (MobileApps) and as literals in the
 * services listed above. Keep in step with them.
 */
final class ApiClientSurfaces
{
    /** Sent by no app — a direct API caller, or a client built before the header existed. */
    public const NOT_SENT = 'not_sent';

    private const SURFACES = [
        'admin-web' => 'Admin Console (web)',
        'admin_mobile_app' => 'Admin Mobile App',
        'frontend-web' => 'Customer Website',
        'customer_mobile_app' => 'Customer Mobile App',
    ];

    /** Longest value the column will hold; anything longer is truncated. */
    public const MAX_LENGTH = 32;

    /**
     * Options for a filter dropdown, including the "didn't send one" case that
     * covers most of the tokens already in the table.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::SURFACES + [self::NOT_SENT => 'No surface reported'];
    }

    public static function label(?string $surface): string
    {
        $value = self::normalise($surface);

        if ($value === null) {
            return 'Not reported';
        }

        // An unrecognised value is shown as sent rather than hidden — it is how
        // a new client, or a misconfigured one, makes itself known.
        return self::SURFACES[$value] ?? 'Unrecognised ('.$value.')';
    }

    public static function isKnown(?string $surface): bool
    {
        return array_key_exists((string) self::normalise($surface), self::SURFACES);
    }

    /** Staff surfaces, mirroring isRequestFromAdminSurface() in app/helpers.php. */
    public static function isAdminSurface(?string $surface): bool
    {
        return in_array(self::normalise($surface), ['admin-web', 'admin_mobile_app'], true);
    }

    /** Trimmed, length-capped, and null when the header was absent or empty. */
    public static function normalise(?string $surface): ?string
    {
        if (! is_string($surface)) {
            return null;
        }

        $value = trim($surface);

        return $value === '' ? null : Str::limit($value, self::MAX_LENGTH, '');
    }
}
