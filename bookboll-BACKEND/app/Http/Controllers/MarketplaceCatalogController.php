<?php

namespace App\Http\Controllers;

use App\Services\BrandResolutionService;
use App\Services\MarketplaceCatalogService;
use App\Services\MarketplaceGeoService;
use Illuminate\Http\Request;

class MarketplaceCatalogController extends Controller
{
    public function __construct(
        protected MarketplaceCatalogService $catalog,
        protected MarketplaceGeoService $geo,
        protected BrandResolutionService $brand,
    ) {
    }

    /**
     * Resolve a provider's white-label storefront from its subdomain slug.
     * Public: the storefront calls this before first paint, signed-out.
     */
    public function brand(Request $request)
    {
        return $this->brand->resolve($request);
    }

    public function listings(Request $request)
    {
        return $this->catalog->listings($request);
    }

    public function venues(Request $request)
    {
        return $this->catalog->venues($request);
    }

    public function venue($id, Request $request)
    {
        return $this->catalog->venue($id, $request);
    }

    public function listing($id, Request $request)
    {
        return $this->catalog->listing($id, $request);
    }

    /**
     * A provider's venues with opening hours — the white-label "Our locations"
     * page and the "Visit Us" block. Public: the storefront renders it signed-out.
     */
    public function brandLocations(Request $request)
    {
        return $this->catalog->brandLocations($request);
    }

    /**
     * The marketplace's own branding — name, colours, logo and meta, as the super
     * admin set them. Public: the storefront needs it before anyone signs in.
     */
    public function platformBrand()
    {
        $keys = [
            'brand_name', 'brand_tagline', 'brand_logo_url', 'brand_primary', 'brand_accent',
            'brand_meta_title', 'brand_meta_description',
            'storefront_sort_space', 'storefront_sort_venue', 'hero_banners',
            'tracking_meta_pixel', 'tracking_ga4', 'tracking_gtm', 'tracking_head',
            // Branding for the back-office chrome itself — the business site's
            // sign-in/signup screens share that mark, so it is served here too.
            'admin_logo_url', 'admin_primary',
        ];

        $settings = \Illuminate\Support\Facades\DB::table('settings')
            ->whereIn('key', $keys)
            ->pluck('value', 'key');

        return sendSuccess('Platform brand', [
            'settings' => collect($keys)->mapWithKeys(
                fn ($key) => [$key => $settings->get($key) ?? '']
            )->all(),
        ]);
    }

    /** Flat catalogue for the build-time sitemap generator. */
    public function sitemap()
    {
        return sendSuccess('Sitemap data', $this->catalog->sitemap());
    }

    public function countries(Request $request)
    {
        return $this->geo->countries($request);
    }

    public function cities(Request $request)
    {
        return $this->geo->cities($request);
    }

    public function city($id)
    {
        return $this->geo->city($id);
    }

    public function directory()
    {
        return $this->geo->directory();
    }

    public function defaultCity(Request $request)
    {
        return $this->geo->defaultCity($request);
    }

    public function servedRegions(Request $request)
    {
        return $this->geo->servedRegions($request);
    }

    public function loyaltyConfig(Request $request)
    {
        return $this->catalog->loyaltyConfig($request);
    }
}
