<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of a provider's white-label storefront settings (Postgres: the matching
 * admin_users columns). `businesses` already carries identity + theme
 * (brand_enabled / brand_subdomain / brand_name / colors / meta); these are the
 * per-site pieces the storefront reads on every page load:
 *
 *   hero_banners      home hero slides
 *   sort_space/venue  fallback ordering when the visitor hasn't shared a location
 *   site_pages        About / Contact content + hero images
 *   brand_languages   offered storefront languages ([] = no restriction)
 *   tracking_*        the provider's OWN analytics/pixels — fire only on their site
 *   brand_whitelabel  premium: hide the "powered by Bookboll" badge
 *   plan              free | premium (the badge only hides while premium is current)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'hero_banners')) {
                $table->json('hero_banners')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'sort_space')) {
                $table->string('sort_space', 40)->default('recommended');
            }
            if (! Schema::hasColumn('businesses', 'sort_venue')) {
                $table->string('sort_venue', 40)->default('recommended');
            }
            if (! Schema::hasColumn('businesses', 'site_pages')) {
                $table->json('site_pages')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'brand_languages')) {
                $table->json('brand_languages')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'tracking_meta_pixel')) {
                $table->string('tracking_meta_pixel')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'tracking_ga4')) {
                $table->string('tracking_ga4')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'tracking_gtm')) {
                $table->string('tracking_gtm')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'tracking_head')) {
                $table->text('tracking_head')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'brand_whitelabel')) {
                $table->boolean('brand_whitelabel')->default(false);
            }
            if (! Schema::hasColumn('businesses', 'plan')) {
                $table->string('plan', 20)->default('free');
            }
            if (! Schema::hasColumn('businesses', 'premium_since')) {
                $table->timestamp('premium_since')->nullable();
            }
        });

        // The storefront resolves a brand by subdomain on every page load.
        if (Schema::hasColumn('businesses', 'brand_subdomain')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->index('brand_subdomain', 'businesses_brand_subdomain_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            foreach ([
                'hero_banners', 'sort_space', 'sort_venue', 'site_pages', 'brand_languages',
                'tracking_meta_pixel', 'tracking_ga4', 'tracking_gtm', 'tracking_head',
                'brand_whitelabel', 'plan', 'premium_since',
            ] as $col) {
                if (Schema::hasColumn('businesses', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
