<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the reminder sweep has already sent (Postgres: public.notification_log).
 *
 * The sweep runs whenever a provider opens their dashboard, so it must be able to run
 * a hundred times a day and still send each reminder once. The unique key is
 * (event, subject, cycle): `cycle_key` is the date the reminder is FOR, so the same
 * subscription is reminded again next cycle but never twice for the same one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_log')) {
            return;
        }

        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 64);        // booking_reminder | review_request | trial_ending | membership_renewal_reminder
            $table->string('subject_type', 32);     // booking | subscription
            $table->string('subject_id', 64);
            $table->string('cycle_key', 32);        // the date the reminder is for
            $table->timestamps();

            // The idempotency guarantee itself — a duplicate send cannot be recorded.
            $table->unique(['event_key', 'subject_type', 'subject_id', 'cycle_key'], 'notification_log_once');
            $table->index('subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
