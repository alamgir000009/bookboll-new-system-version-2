<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who registered a business, and the key that makes registering it safe to
 * retry.
 *
 * A business created by platform staff has no owner account to attribute it to,
 * so without `created_by` there is no record of who added it. The idempotency
 * key is what lets a lost response be retried without producing a second
 * business — the create endpoint returns the original row instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->index()->after('owner_id');
            }
            if (! Schema::hasColumn('businesses', 'idempotency_key')) {
                // Unique, so a retry collides instead of inserting again.
                $table->string('idempotency_key', 120)->nullable()->unique();
            }
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'idempotency_key')) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            }
            if (Schema::hasColumn('businesses', 'created_by')) {
                $table->dropColumn('created_by');
            }
        });
    }
};
