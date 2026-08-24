<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand payments.type so subscription ledger rows can use
 * subscription_commission / subscription_earning (admin Subscribers drawer).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        DB::statement("ALTER TABLE `payments` MODIFY COLUMN `type` ENUM(
            'full',
            'deposit',
            'remaining',
            'commission',
            'earning',
            'booking_commission',
            'booking_earning',
            'subscription_commission',
            'subscription_earning'
        ) NOT NULL DEFAULT 'full'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        DB::statement("ALTER TABLE `payments` MODIFY COLUMN `type` ENUM(
            'full',
            'deposit',
            'remaining'
        ) NOT NULL DEFAULT 'full'");
    }
};
