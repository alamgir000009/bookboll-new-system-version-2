<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence that an account has actually signed in from a native app.
 *
 * The websites keep a per-account browser self-report, which does not sync
 * between devices and is not evidence of anything: a OneLink click, a QR
 * display, a user agent or "I already have it" cannot establish that an
 * account installed an app. This table holds the one thing that can — an
 * authenticated call from the app itself.
 *
 * One row per installation per app audience. The installation is bound to the
 * account that is signed in when the app calls, and rebinding on a shared
 * device must not attribute later activity to the previous account: hence
 * (app, installation_id) unique, with user_id rewritten on handover and the
 * first-use timestamp reset with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_installations', function (Blueprint $table) {
            $table->id();

            // customer | admin. The same human may hold both identities, and
            // their two adoption states stay separate.
            $table->string('app', 16);

            // Random per-install UUID from the client. Never an advertising or
            // device identifier, and never exposed through a public endpoint.
            $table->string('installation_id', 64);

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();

            // Server clocks only. A client-supplied time is a claim.
            // datetime, not timestamp: MariaDB gives only the first TIMESTAMP
            // column an implicit default, and the second would be rejected.
            $table->dateTime('first_authenticated_use_at');
            $table->dateTime('last_authenticated_use_at');

            // When this installation was last bound to a different account, so
            // a handover is auditable rather than silent.
            $table->dateTime('rebound_at')->nullable();

            $table->timestamps();

            $table->unique(['app', 'installation_id']);
            // The adoption report reads by account and audience.
            $table->index(['user_id', 'app', 'last_authenticated_use_at'], 'mai_account_app_recent');
            // Retention sweeps read by last use alone.
            $table->index('last_authenticated_use_at', 'mai_last_use');
        });

        Schema::table('users', function (Blueprint $table) {
            // The self-report, promoted from browser storage so it survives a
            // new device. Deliberately separate from native-use evidence: it is
            // a preference, not proof, and it must be undoable.
            $table->json('mobile_app_self_reported')->nullable()->after('notification_preferences');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_installations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mobile_app_self_reported');
        });
    }
};
