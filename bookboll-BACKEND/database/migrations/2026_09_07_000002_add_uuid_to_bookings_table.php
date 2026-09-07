<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The desk's "Pay by QR" link (/pay/{id}) is opened by a customer with no session,
 * so the identifier in it IS the credential. bookings.id is sequential and
 * booking_id reads BKN_2026_09_00001 — either would let anyone walk the table and
 * read every customer's name, venue and balance. A uuid closes that.
 *
 * Same pattern as communities, hosted_games and class_subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings') || Schema::hasColumn('bookings', 'uuid')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        DB::table('bookings')->whereNull('uuid')->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('bookings')->where('id', $row->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings') || ! Schema::hasColumn('bookings', 'uuid')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
