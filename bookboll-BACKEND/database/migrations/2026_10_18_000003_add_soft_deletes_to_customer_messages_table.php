<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes for chat messages, so a moderator removing an unwanted group
 * message hides it without destroying the record behind it — moderation needs
 * to be reviewable, and "who deleted what" is the first question asked.
 *
 * Adding the column is also what makes the removal complete: every read of this
 * table goes through the CustomerMessage model, so the SoftDeletes trait takes
 * deleted messages out of the thread, the unread counts and the inbox's
 * last-message preview in one move, rather than each of those needing its own
 * exclusion that someone could forget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_messages', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_messages', function (Blueprint $table) {
            if (Schema::hasColumn('customer_messages', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
