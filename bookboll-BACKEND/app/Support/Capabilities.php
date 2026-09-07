<?php

namespace App\Support;

/**
 * Capabilities — the single source of truth for what each role may DO.
 *
 * A direct port of the dashboard's lib/permissions.js, deliberately keeping the
 * same names and the same role→capability sets so the API enforces exactly what
 * the UI offers. Anything that changes here must change there too.
 *
 *   PLATFORM (Bookboll)             PROVIDER (a business, top → bottom)
 *   ─────────────────────────       ──────────────────────────────────────────
 *   super_admin    everything       provider_admin       Owner: full control
 *   country_admin  their countries  facility_admin       Manager: all but payouts
 *   platform_staff ops + approvals  provider_supervisor  Supervisor: ops + catalog
 *                                   staff                Staff: ops + payments
 *                                   provider_viewer      Viewer: read-only
 *
 * These live alongside the older `module.action` permissions, which still back
 * the legacy admin / senior_employee / junior_employee / guard roles.
 */
class Capabilities
{
    // ── Operations ───────────────────────────────────────────────────────────
    public const VIEW_CALENDAR        = 'view_calendar';
    public const VIEW_BOOKINGS        = 'view_bookings';
    public const MANAGE_BOOKINGS      = 'manage_bookings';
    public const VIEW_SUBSCRIPTIONS   = 'view_subscriptions';
    public const MANAGE_SUBSCRIPTIONS = 'manage_subscriptions';
    public const MANAGE_CUSTOMERS     = 'manage_customers';
    public const ACCEPT_PAYMENTS      = 'accept_payments';

    // ── Business ─────────────────────────────────────────────────────────────
    public const MANAGE_LISTINGS      = 'manage_listings';
    public const MANAGE_PROMOCODES    = 'manage_promocodes';
    public const MANAGE_WEBSITE       = 'manage_website';
    public const MANAGE_WEBSITE_BLOG  = 'manage_website_blog';
    public const VIEW_WALLET          = 'view_wallet';
    public const MANAGE_PAYOUTS       = 'manage_payouts';
    public const WITHDRAW_FUNDS       = 'withdraw_funds';
    public const MANAGE_TEAM          = 'manage_team';
    public const MODERATE_EVENTS      = 'moderate_events';

    // ── Platform administration ──────────────────────────────────────────────
    public const MANAGE_GEOGRAPHY           = 'manage_geography';
    public const MANAGE_LOYALTY             = 'manage_loyalty';
    public const MANAGE_PLATFORM_WEBSITE    = 'manage_platform_website';
    public const MANAGE_PLATFORM_BLOG       = 'manage_platform_blog';
    public const MANAGE_PROMOCODES_PLATFORM = 'manage_promocodes_platform';
    public const MANAGE_PLATFORM_CATALOG    = 'manage_platform_catalog';
    public const APPROVE_LISTINGS           = 'approve_listings';
    public const APPROVE_WITHDRAWALS        = 'approve_withdrawals';
    public const APPROVE_PAYOUTS            = 'approve_payouts';
    public const PLATFORM_ADMIN             = 'platform_admin';

    // ── Per-page exports ─────────────────────────────────────────────────────
    public const EXPORT_BOOKINGS      = 'export_bookings';
    public const EXPORT_PAYMENTS      = 'export_payments';
    public const EXPORT_CUSTOMERS     = 'export_customers';
    public const EXPORT_SUBSCRIPTIONS = 'export_subscriptions';
    public const EXPORT_CATALOG       = 'export_catalog';

    /** @return string[] every capability, in a stable order */
    public static function all(): array
    {
        return [
            self::VIEW_CALENDAR, self::VIEW_BOOKINGS, self::MANAGE_BOOKINGS,
            self::VIEW_SUBSCRIPTIONS, self::MANAGE_SUBSCRIPTIONS, self::MANAGE_CUSTOMERS,
            self::ACCEPT_PAYMENTS, self::MANAGE_LISTINGS, self::MANAGE_PROMOCODES,
            self::MANAGE_WEBSITE, self::MANAGE_WEBSITE_BLOG, self::VIEW_WALLET,
            self::MANAGE_PAYOUTS, self::WITHDRAW_FUNDS, self::MANAGE_TEAM,
            self::MODERATE_EVENTS, self::MANAGE_GEOGRAPHY, self::MANAGE_LOYALTY,
            self::MANAGE_PLATFORM_WEBSITE, self::MANAGE_PLATFORM_BLOG,
            self::MANAGE_PROMOCODES_PLATFORM, self::MANAGE_PLATFORM_CATALOG,
            self::APPROVE_LISTINGS, self::APPROVE_WITHDRAWALS, self::APPROVE_PAYOUTS,
            self::PLATFORM_ADMIN,
            self::EXPORT_BOOKINGS, self::EXPORT_PAYMENTS, self::EXPORT_CUSTOMERS,
            self::EXPORT_SUBSCRIPTIONS, self::EXPORT_CATALOG,
        ];
    }

    /** @return string[] */
    public static function exports(): array
    {
        return [
            self::EXPORT_BOOKINGS, self::EXPORT_PAYMENTS, self::EXPORT_CUSTOMERS,
            self::EXPORT_SUBSCRIPTIONS, self::EXPORT_CATALOG,
        ];
    }

    /** @return string[] */
    public static function platformAdminCaps(): array
    {
        return [
            self::MANAGE_GEOGRAPHY, self::MANAGE_LOYALTY, self::MANAGE_PLATFORM_WEBSITE,
            self::MANAGE_PLATFORM_BLOG, self::MANAGE_PROMOCODES_PLATFORM,
            self::MANAGE_PROMOCODES, self::MANAGE_PLATFORM_CATALOG,
            self::APPROVE_LISTINGS, self::APPROVE_WITHDRAWALS, self::APPROVE_PAYOUTS,
        ];
    }

    /** Shared operations set — the day-to-day desk work. @return string[] */
    public static function opsCaps(): array
    {
        return [
            self::VIEW_CALENDAR, self::VIEW_BOOKINGS, self::MANAGE_BOOKINGS,
            self::VIEW_SUBSCRIPTIONS, self::MANAGE_SUBSCRIPTIONS,
            self::MANAGE_CUSTOMERS, self::ACCEPT_PAYMENTS,
        ];
    }

    // ── Provider tiers, each building on the one below ────────────────────────

    /** @return string[] */
    public static function viewerCaps(): array
    {
        return [self::VIEW_CALENDAR, self::VIEW_BOOKINGS, self::VIEW_SUBSCRIPTIONS];
    }

    /** @return string[] */
    public static function staffCaps(): array
    {
        return array_merge(self::viewerCaps(), [
            self::MANAGE_BOOKINGS, self::MANAGE_SUBSCRIPTIONS,
            self::MANAGE_CUSTOMERS, self::ACCEPT_PAYMENTS,
        ]);
    }

    /**
     * Supervisor adds catalog, promotions and moderation. Exports, website and
     * wallet are NOT base — the owner/manager grants those per person.
     *
     * @return string[]
     */
    public static function supervisorCaps(): array
    {
        return array_merge(self::staffCaps(), [
            self::MANAGE_LISTINGS, self::MANAGE_PROMOCODES, self::MODERATE_EVENTS,
        ]);
    }

    /** Manager = the whole business except editing payout details. @return string[] */
    public static function managerCaps(): array
    {
        return array_merge(self::supervisorCaps(), self::exports(), [
            self::MANAGE_WEBSITE, self::MANAGE_WEBSITE_BLOG, self::MANAGE_TEAM,
            self::VIEW_WALLET, self::WITHDRAW_FUNDS,
        ]);
    }

    /** @return string[] */
    public static function ownerCaps(): array
    {
        return array_merge(self::managerCaps(), [self::MANAGE_PAYOUTS]);
    }

    /**
     * Country Admin is super-equivalent but scoped to their countries, so they do
     * NOT hold the global Bookboll-HQ content caps (there is one marketplace site,
     * blog and catalog for everyone) nor the provider-own-website caps (they have
     * no white-label of their own). Everything else they get, enforced
     * per-jurisdiction by the query scoping.
     *
     * @return string[]
     */
    public static function countryAdminCaps(): array
    {
        $exclude = [
            self::MANAGE_PLATFORM_WEBSITE, self::MANAGE_PLATFORM_BLOG,
            self::MANAGE_PLATFORM_CATALOG, self::MANAGE_WEBSITE, self::MANAGE_WEBSITE_BLOG,
        ];

        return array_values(array_diff(self::all(), $exclude));
    }

    /**
     * role → capabilities. Mirrors ROLE_CAPS in lib/permissions.js.
     *
     * @return array<string, string[]>
     */
    public static function roleMap(): array
    {
        return [
            // Platform
            'super_admin'    => self::all(),
            // Legacy Laravel role that predates the split; the dashboard treats it
            // as a full platform owner (currentUser.js pickPrimaryRole).
            'admin'          => self::all(),
            'country_admin'  => self::countryAdminCaps(),
            'platform_staff' => array_values(array_unique(
                array_merge(self::platformAdminCaps(), self::opsCaps())
            )),

            // Provider
            'provider_admin'      => self::ownerCaps(),
            'facility_admin'      => self::managerCaps(),
            'provider_supervisor' => self::supervisorCaps(),
            'staff'               => self::staffCaps(),
            'provider_viewer'     => self::viewerCaps(),

            // Legacy provider-side rows kept working
            'senior_employee' => [
                self::VIEW_CALENDAR, self::VIEW_BOOKINGS, self::MANAGE_BOOKINGS,
                self::MANAGE_CUSTOMERS, self::ACCEPT_PAYMENTS,
            ],
            'junior_employee' => [self::VIEW_CALENDAR, self::VIEW_BOOKINGS, self::MANAGE_BOOKINGS],
            'guard'           => [self::VIEW_CALENDAR, self::VIEW_BOOKINGS],
        ];
    }

    /** Roles that see the whole platform, unscoped. @return string[] */
    public static function globalRoles(): array
    {
        return ['super_admin', 'admin'];
    }

    /** Platform-side roles (scoped by jurisdiction, not ownership). @return string[] */
    public static function platformRoles(): array
    {
        return ['super_admin', 'admin', 'country_admin', 'platform_staff'];
    }

    /** Provider-side roles (scoped by business ownership). @return string[] */
    public static function providerRoles(): array
    {
        return [
            'provider_admin', 'facility_admin', 'provider_supervisor',
            'staff', 'provider_viewer', 'senior_employee', 'junior_employee', 'guard',
        ];
    }
}
