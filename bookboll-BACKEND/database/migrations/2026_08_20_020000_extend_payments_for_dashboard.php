<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align payments with the admin dashboard's booking_payments ledger shape
 * (currency, recipient, paid_at, source_type, subscription_id, marked_paid_by)
 * while keeping legacy booking payment rows working.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'currency')) {
                $table->string('currency', 10)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('payments', 'recipient')) {
                $table->string('recipient')->nullable()->after('currency');
            }
            if (! Schema::hasColumn('payments', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('date');
            }
            if (! Schema::hasColumn('payments', 'source_type')) {
                $table->string('source_type', 32)->default('booking')->after('booking_id');
            }
            if (! Schema::hasColumn('payments', 'subscription_id')) {
                $table->unsignedBigInteger('subscription_id')->nullable()->after('source_type')->index();
            }
            if (! Schema::hasColumn('payments', 'marked_paid_by')) {
                $table->unsignedBigInteger('marked_paid_by')->nullable()->after('paid_at')->index();
            }
        });

        // Allow subscription-only ledger rows later (booking_id optional).
        if (Schema::hasColumn('payments', 'booking_id')) {
            try {
                Schema::table('payments', function (Blueprint $table) {
                    $table->unsignedBigInteger('booking_id')->nullable()->change();
                });
            } catch (\Throwable $e) {
                // Some drivers / FK setups refuse change(); non-fatal for listing.
            }
        }

        // Backfill paid_at / recipient / currency for existing rows.
        DB::table('payments')->whereNull('source_type')->update(['source_type' => 'booking']);

        DB::table('payments')
            ->where('status', 'paid')
            ->whereNull('paid_at')
            ->update([
                'paid_at' => DB::raw('COALESCE(date, created_at)'),
            ]);

        DB::table('payments')
            ->whereNull('recipient')
            ->where('method', 'online')
            ->update(['recipient' => '@bookboll']);

        DB::table('payments')
            ->whereNull('currency')
            ->update(['currency' => 'QAR']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            foreach (['currency', 'recipient', 'paid_at', 'source_type', 'subscription_id', 'marked_paid_by'] as $col) {
                if (Schema::hasColumn('payments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
