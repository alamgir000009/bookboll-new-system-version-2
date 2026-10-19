<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which catalogue categories people actually choose.
 *
 * Ranking by listing count tells you what the operator has most of, not what
 * visitors want. These are explicit selections only — not page renders, not a
 * restored URL, not All Venues, not re-clicking the category already open.
 *
 * Deliberately anonymous. No customer id, no contact detail, no coordinates:
 * the visitor hash exists only to stop one person's repeated clicks counting
 * as popularity, and it is derived, salted and never reversed.
 *
 * Scoped by city, country and storefront, because marketplace activity must
 * not reorder a provider's own website and vice versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_clicks', function (Blueprint $table) {
            $table->id();
            $table->string('category', 64)->index();
            // '' for a category with no sport beneath it — the document's
            // "empty string for a category without a type".
            $table->string('type', 96)->default('');
            $table->enum('source', ['home', 'catalog']);

            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->unsignedBigInteger('country_id')->nullable()->index();
            // Null means the Bookboll marketplace; a slug means that provider's
            // own storefront.
            $table->string('brand_slug', 64)->nullable()->index();

            // Salted hash of request origin, used only to collapse repeats.
            $table->char('visitor_hash', 64)->index();

            $table->timestamp('created_at')->index();

            // The aggregation path: this scope, this window.
            $table->index(['country_id', 'city_id', 'brand_slug', 'created_at'], 'category_clicks_scope_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_clicks');
    }
};
