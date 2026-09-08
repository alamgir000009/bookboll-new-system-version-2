<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\Post;
use App\Models\Venue;
use App\Models\Setting;
use App\Support\AccessScope;
use Illuminate\Http\Request;

/**
 * Everything behind the "My Website" pages — Site, Home banners, Custom domain,
 * Embed, Blog and Tracking.
 *
 * One surface, two destinations, decided by who is asking (the same shape as
 * StorefrontSortService):
 *   • a provider edits THEIR white-label site  → their businesses row
 *   • a platform admin edits the marketplace   → settings rows
 *
 * The destination is never taken from the request, so a provider cannot write
 * the marketplace's branding by passing a flag.
 */
class SiteSettingsService
{
    /** Fields a PROVIDER may edit — column on businesses. */
    protected const PROVIDER_FIELDS = [
        'brand_enabled', 'brand_subdomain', 'brand_name', 'brand_tagline',
        'brand_logo_url', 'brand_primary', 'brand_accent',
        'brand_meta_title', 'brand_meta_description',
        'brand_show_venues', 'brand_show_events', 'brand_show_programs',
        'brand_languages', 'brand_whitelabel',
        'brand_custom_domain', 'brand_domain_status', 'brand_domain_paid_at',
        'site_pages', 'hero_banners',
        'tracking_meta_pixel', 'tracking_ga4', 'tracking_gtm', 'tracking_head',
    ];

    /** Fields a PLATFORM ADMIN may edit — key in settings. */
    protected const PLATFORM_FIELDS = [
        'brand_name', 'brand_tagline', 'brand_logo_url', 'brand_primary', 'brand_accent',
        'brand_meta_title', 'brand_meta_description',
        'admin_logo_url', 'admin_primary',
        'hero_banners',
        'tracking_meta_pixel', 'tracking_ga4', 'tracking_gtm', 'tracking_head',
    ];

    /** Stored as JSON rather than a plain string. */
    protected const JSON_FIELDS = ['site_pages', 'hero_banners', 'brand_languages'];

    protected const BOOL_FIELDS = [
        'brand_enabled', 'brand_whitelabel',
        'brand_show_venues', 'brand_show_events', 'brand_show_programs',
    ];

    public function show(Request $request)
    {
        $user = $request->user();
        $ownerId = AccessScope::ownerUserId($user);

        if ($ownerId !== null) {
            $b = Business::where('owner_id', $ownerId)->first();

            return sendSuccess('Site settings', [
                'mode'     => 'provider',
                'settings' => $this->providerPayload($b),
                'username' => $b?->username,
                'plan'     => $b?->plan ?: 'free',
                'counts'   => $this->providerCounts($ownerId),
            ]);
        }

        return sendSuccess('Site settings', [
            'mode'     => 'platform',
            'settings' => $this->platformPayload(),
            'username' => null,
            'plan'     => 'premium',
            'counts'   => ['venues' => 0, 'programs' => 0, 'posts' => 0],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $ownerId = AccessScope::ownerUserId($user);

        return $ownerId !== null
            ? $this->updateProvider($request, $ownerId)
            : $this->updatePlatform($request);
    }

    protected function updateProvider(Request $request, int $ownerId)
    {
        $business = Business::where('owner_id', $ownerId)->first();
        if (! $business) {
            return sendError('No business found for this account.', [], 404);
        }

        $patch = [];
        foreach (self::PROVIDER_FIELDS as $field) {
            if (! $request->has($field)) {
                continue;
            }
            $patch[$field] = $this->normalize($field, $request->input($field));
        }

        if (! $patch) {
            return sendError('Nothing to save.');
        }

        // A web address is the site's identity, so it must stay unique and may
        // not take one of the reserved subdomains.
        if (array_key_exists('brand_subdomain', $patch)) {
            $slug = BusinessService::normalizeUsername((string) $patch['brand_subdomain']);
            if ($slug === '') {
                unset($patch['brand_subdomain']);
            } else {
                if (BusinessService::isReservedUsername($slug)) {
                    return sendError('That web address is reserved. Please choose another.', [
                        'brand_subdomain' => ['That web address is reserved.'],
                    ], 422);
                }
                $taken = Business::where('id', '!=', $business->id)
                    ->where(function ($q) use ($slug) {
                        $q->where('brand_subdomain', $slug)->orWhere('username', $slug);
                    })->exists();
                if ($taken) {
                    return sendError('That web address is already taken.', [
                        'brand_subdomain' => ['That web address is already taken.'],
                    ], 422);
                }
                $patch['brand_subdomain'] = $slug;
            }
        }

        $business->update($patch);

        return sendSuccess('Site settings saved', [
            'mode'     => 'provider',
            'settings' => $this->providerPayload($business->fresh()),
            'username' => $business->username,
            'plan'     => $business->plan ?: 'free',
        ]);
    }

    protected function updatePlatform(Request $request)
    {
        $saved = 0;
        foreach (self::PLATFORM_FIELDS as $field) {
            if (! $request->has($field)) {
                continue;
            }
            $value = $this->normalize($field, $request->input($field));
            Setting::updateOrCreate(
                ['key' => $field],
                ['value' => in_array($field, self::JSON_FIELDS, true) ? json_encode($value) : (string) $value]
            );
            $saved++;
        }

        if (! $saved) {
            return sendError('Nothing to save.');
        }

        return sendSuccess('Site settings saved', [
            'mode'     => 'platform',
            'settings' => $this->platformPayload(),
            'username' => null,
            'plan'     => 'premium',
        ]);
    }

    protected function normalize(string $field, $value)
    {
        if (in_array($field, self::BOOL_FIELDS, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }
        if (in_array($field, self::JSON_FIELDS, true)) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);

                return is_array($decoded) ? $decoded : [];
            }

            return is_array($value) ? $value : [];
        }

        return $value === '' ? null : $value;
    }

    /**
     * How much content is actually LIVE on the provider's site — what the Site
     * page shows next to each storefront section. A missing
     * show_on_provider_site counts as visible, matching the storefront.
     *
     * @return array{venues: int, programs: int, posts: int}
     */
    protected function providerCounts(int $ownerId): array
    {
        $venueIds = Venue::where('owner_user_id', $ownerId)->pluck('id');

        $venues = $venueIds->isEmpty() ? 0 : Listing::whereIn('venue_id', $venueIds)
            ->where(function ($q) {
                $q->where('show_on_provider_site', true)->orWhereNull('show_on_provider_site');
            })->count();

        $programs = ClassProgram::query()
            ->where(function ($q) use ($ownerId) {
                $q->where('mapped_owner_user_id', $ownerId)->orWhere('owner_user_id', (string) $ownerId);
            })
            ->where(function ($q) {
                $q->where('show_on_provider_site', true)->orWhereNull('show_on_provider_site');
            })->count();

        $posts = Post::where('owner_user_id', $ownerId)->where('status', 'published')->count();

        return ['venues' => $venues, 'programs' => $programs, 'posts' => $posts];
    }

    protected function providerPayload(?Business $b): array
    {
        if (! $b) {
            return array_fill_keys(self::PROVIDER_FIELDS, null);
        }

        return [
            'brand_enabled'          => (bool) $b->brand_enabled,
            'brand_subdomain'        => $b->brand_subdomain ?: $b->username,
            'brand_name'             => $b->brand_name ?: $b->name,
            'brand_tagline'          => $b->brand_tagline ?: '',
            'brand_logo_url'         => $b->brand_logo_url ?: '',
            'brand_primary'          => $b->brand_primary ?: '',
            'brand_accent'           => $b->brand_accent ?: '',
            'brand_meta_title'       => $b->brand_meta_title ?: '',
            'brand_meta_description' => $b->brand_meta_description ?: '',
            // NULL means "never set" and reads as ON, so an existing site keeps
            // showing every section until the provider turns one off.
            'brand_show_venues'      => $b->brand_show_venues !== false,
            'brand_show_events'      => $b->brand_show_events !== false,
            'brand_show_programs'    => $b->brand_show_programs !== false,
            'brand_languages'        => is_array($b->brand_languages) ? $b->brand_languages : [],
            'brand_whitelabel'       => (bool) $b->brand_whitelabel,
            'brand_custom_domain'    => $b->brand_custom_domain ?: '',
            'brand_domain_status'    => $b->brand_domain_status ?: '',
            'brand_domain_paid_at'   => optional($b->brand_domain_paid_at)?->toIso8601String(),
            'site_pages'             => is_array($b->site_pages) ? $b->site_pages : [],
            'hero_banners'           => is_array($b->hero_banners) ? $b->hero_banners : [],
            'tracking_meta_pixel'    => $b->tracking_meta_pixel ?: '',
            'tracking_ga4'           => $b->tracking_ga4 ?: '',
            'tracking_gtm'           => $b->tracking_gtm ?: '',
            'tracking_head'          => $b->tracking_head ?: '',
        ];
    }

    protected function platformPayload(): array
    {
        $rows = Setting::whereIn('key', self::PLATFORM_FIELDS)->pluck('value', 'key');

        $out = [];
        foreach (self::PLATFORM_FIELDS as $field) {
            $raw = $rows[$field] ?? null;
            if (in_array($field, self::JSON_FIELDS, true)) {
                $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
                $out[$field] = is_array($decoded) ? $decoded : [];

                continue;
            }
            $out[$field] = $raw ?? '';
        }

        return $out;
    }
}
