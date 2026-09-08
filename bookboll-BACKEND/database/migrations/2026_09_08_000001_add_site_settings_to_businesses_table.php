<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last of a provider's white-label settings (Postgres: the matching
 * admin_users columns), completing what the "My Website" pages read and write.
 *
 *   brand_show_venues/_events/_programs  which storefront sections they show.
 *                                        NULL means "not set" and reads as ON,
 *                                        so an existing site keeps every section.
 *   brand_custom_domain / _status        their own domain instead of the
 *   brand_domain_paid_at                 <slug>.bookboll.com address.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            foreach (['brand_show_venues', 'brand_show_events', 'brand_show_programs'] as $col) {
                if (! Schema::hasColumn('businesses', $col)) {
                    $table->boolean($col)->nullable();
                }
            }
            if (! Schema::hasColumn('businesses', 'brand_custom_domain')) {
                $table->string('brand_custom_domain')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'brand_domain_status')) {
                $table->string('brand_domain_status', 30)->nullable();
            }
            if (! Schema::hasColumn('businesses', 'brand_domain_paid_at')) {
                $table->timestamp('brand_domain_paid_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            foreach ([
                'brand_show_venues', 'brand_show_events', 'brand_show_programs',
                'brand_custom_domain', 'brand_domain_status', 'brand_domain_paid_at',
            ] as $col) {
                if (Schema::hasColumn('businesses', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
