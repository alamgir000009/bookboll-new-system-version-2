<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's own WhatsApp Business sender (Postgres: admin_users.whatsapp_config).
 *
 * Each provider connects THEIR number + token and markets their own customers from
 * it; Bookboll's own number lives in `settings` under the same key. One JSON column
 * rather than a table: it is a single config blob per business, edited whole, read
 * whole — exactly like the `notification_templates` column beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('businesses') || Schema::hasColumn('businesses', 'whatsapp_config')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            $table->json('whatsapp_config')->nullable()->after('notification_templates');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('whatsapp_config');
        });
    }
};
