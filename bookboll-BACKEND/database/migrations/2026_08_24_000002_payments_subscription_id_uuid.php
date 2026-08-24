<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow payments.subscription_id to store class_subscriptions UUID ids.
 * Add joining_fee on class_subscriptions for first-charge parity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'subscription_id')) {
            // Drop FK if any, then widen column to char(36) for UUIDs.
            try {
                Schema::table('payments', function (Blueprint $table) {
                    $table->dropForeign(['subscription_id']);
                });
            } catch (\Throwable) {
                // no FK
            }

            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE payments MODIFY subscription_id CHAR(36) NULL');
            } else {
                Schema::table('payments', function (Blueprint $table) {
                    $table->string('subscription_id', 36)->nullable()->change();
                });
            }
        }

        if (Schema::hasTable('class_subscriptions') && ! Schema::hasColumn('class_subscriptions', 'joining_fee')) {
            Schema::table('class_subscriptions', function (Blueprint $table) {
                $table->decimal('joining_fee', 12, 2)->default(0)->after('platform_fee');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('class_subscriptions') && Schema::hasColumn('class_subscriptions', 'joining_fee')) {
            Schema::table('class_subscriptions', function (Blueprint $table) {
                $table->dropColumn('joining_fee');
            });
        }
        // Do not shrink subscription_id back — may already hold UUIDs.
    }
};
