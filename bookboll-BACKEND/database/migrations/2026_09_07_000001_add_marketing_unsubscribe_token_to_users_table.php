<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every marketing email carries a one-click unsubscribe link, so each customer
 * needs a stable, unguessable token that identifies them without a sign-in
 * (Postgres: customers.unsubscribe_token). `marketing_updated_at` records when
 * consent last changed — the audit trail behind an opt-out claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'unsubscribe_token')) {
                $table->string('unsubscribe_token', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'marketing_updated_at')) {
                $table->timestamp('marketing_updated_at')->nullable();
            }
        });

        // Backfill: existing customers must be unsubscribable too.
        DB::table('users')->whereNull('unsubscribe_token')->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('users')->where('id', $row->id)
                        ->update(['unsubscribe_token' => Str::random(48)]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'unsubscribe_token')) {
                $table->dropUnique(['unsubscribe_token']);
                $table->dropColumn('unsubscribe_token');
            }
            if (Schema::hasColumn('users', 'marketing_updated_at')) {
                $table->dropColumn('marketing_updated_at');
            }
        });
    }
};
