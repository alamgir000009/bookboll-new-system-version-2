<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which OneSignal app a device belongs to.
 *
 * The admin and customer apps are separate OneSignal apps (Apple ties an APNs
 * configuration to one bundle id, so they cannot share one). A subscription id
 * only resolves inside the app that issued it, so every row has to say which
 * app's credentials can reach it. Existing rows all came from the admin app —
 * it was the only client registering devices — hence the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onesignal_subscriptions', function (Blueprint $table) {
            $table->string('app', 16)
                ->default('admin')
                ->after('onesignal_id')
                ->comment('admin or customer — which OneSignal app issued onesignal_id');
            $table->index(['app', 'is_active']);
        });

        // The column default covers inserts; this covers the rows already there
        // on databases where the ALTER does not rewrite existing values.
        DB::table('onesignal_subscriptions')->whereNull('app')->update(['app' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('onesignal_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['app', 'is_active']);
            $table->dropColumn('app');
        });
    }
};
