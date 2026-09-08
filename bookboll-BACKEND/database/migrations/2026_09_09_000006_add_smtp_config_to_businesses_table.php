<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's own email sending identity (Postgres: admin_users.smtp_config).
 *
 * The sibling of whatsapp_config: one JSON blob per business, edited whole and read
 * whole, so a column rather than a table — the same shape as notification_templates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('businesses') || Schema::hasColumn('businesses', 'smtp_config')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            $table->json('smtp_config')->nullable()->after('whatsapp_config');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('smtp_config');
        });
    }
};
