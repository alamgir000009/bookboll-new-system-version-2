<?php

namespace App\Support;

use App\Models\MobileAppInstallation;
use App\Models\User;
use App\Support\Capabilities as Caps;

/**
 * What we actually know about an account using a mobile app.
 *
 * The honest states, and the document is emphatic about them:
 *
 *   unknown   no authenticated app use on record. NOT "not downloaded" —
 *             people install apps without signing in, and a click, a QR scan,
 *             a user agent or a OneLink redirect proves nothing.
 *   used_app  the account signed in from a supported native app. Evidence.
 *
 * `self_reported` is a third thing and stays separate: a preference someone
 * set by tapping "I already have the app". It silences the prompt and is
 * undoable. It is never evidence, and it is never reported as use.
 *
 * Last authenticated use cannot prove the app is still installed, and nothing
 * here detects an uninstall. The report says "Used the app" / "No confirmed
 * app use" for exactly that reason.
 */
class MobileAppAdoption
{
    /** The two audiences. A human with both identities has two separate states. */
    public const APPS = ['customer', 'admin'];

    public const PLATFORMS = ['ios', 'android'];

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_USED = 'used_app';

    /**
     * How recently an account must have used the app for the website to stop
     * asking. Past this, prompting resumes — the app may be gone, and we
     * cannot tell.
     */
    public const RECENT_USE_DAYS = 90;

    /**
     * How long an installation record is kept after its last authenticated
     * use. Documented here because the document asks for a documented policy,
     * and enforced by mobile-app:prune-installations.
     */
    public const RETENTION_DAYS = 540;

    /**
     * Native clients call on login and on foreground use. Calls closer
     * together than this touch nothing, so a chatty app cannot rewrite its own
     * last-use timestamp hundreds of times an hour.
     */
    public const REFRESH_MINUTES = 60;

    /** The capability that may read platform-wide adoption. */
    public const REPORT_CAPABILITY = Caps::PLATFORM_ADMIN;

    public static function isApp(?string $app): bool
    {
        return is_string($app) && in_array($app, self::APPS, true);
    }

    /**
     * May this account register use of this app audience?
     *
     * The admin app is for business identities. A customer signing into the
     * admin app would otherwise appear in provider adoption figures.
     */
    public static function mayUse(?User $user, string $app): bool
    {
        if (! $user) {
            return false;
        }

        return $app === 'admin'
            ? (AccessScope::isProviderScoped($user) || AccessScope::isPlatform($user) || AccessScope::isGlobal($user))
            : $user->hasRole('customer');
    }

    /**
     * This account's state for this app.
     *
     * @return array<string, mixed>
     */
    public static function status(?User $user, string $app): array
    {
        $row = $user
            ? MobileAppInstallation::where('user_id', $user->id)->where('app', $app)
                ->orderByDesc('last_authenticated_use_at')->first()
            : null;

        $selfReported = self::selfReported($user, $app);
        $used = $row !== null;
        $recent = $used && $row->last_authenticated_use_at
            && $row->last_authenticated_use_at->greaterThanOrEqualTo(now()->subDays(self::RECENT_USE_DAYS));

        return [
            'app'    => $app,
            // Evidence only. A self-report never becomes used_app.
            'usage_status' => $used ? self::STATUS_USED : self::STATUS_UNKNOWN,
            // unknown carries null evidence, so a caller cannot mistake an
            // absent timestamp for a zero one.
            'first_authenticated_use_at' => $used ? $row->first_authenticated_use_at?->toIso8601String() : null,
            'last_authenticated_use_at'  => $used ? $row->last_authenticated_use_at?->toIso8601String() : null,
            'self_reported'  => $selfReported,
            // The server decides suppression; the website obeys it and falls
            // back to its per-visit prompt when it cannot ask.
            'suppress_prompt' => $recent || $selfReported,
            'policy' => [
                'recent_use_days' => self::RECENT_USE_DAYS,
                'reason' => $recent
                    ? 'recent_authenticated_use'
                    : ($selfReported ? 'self_reported' : null),
            ],
        ];
    }

    public static function selfReported(?User $user, string $app): bool
    {
        $saved = is_array($user?->mobile_app_self_reported) ? $user->mobile_app_self_reported : [];

        return ! empty($saved[$app]);
    }

    /**
     * Human-readable evidence state for the report.
     *
     * "Used the app" / "No confirmed app use" — never "Downloaded" against
     * "Not downloaded", which this data cannot support.
     */
    public static function evidenceLabel(string $status): string
    {
        return $status === self::STATUS_USED ? 'Used the app' : 'No confirmed app use';
    }
}
