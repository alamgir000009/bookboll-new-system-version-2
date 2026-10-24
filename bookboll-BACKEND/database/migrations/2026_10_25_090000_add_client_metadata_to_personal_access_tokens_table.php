<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum records a token's name and when it was last used, and nothing about
 * who is holding it. The admin panel's session list needs to answer "where is
 * this person signed in from, and on what" — so each token now carries the
 * address and user agent it was issued to, plus the address and user agent that
 * last presented it. The pair is what makes a stolen token visible: a session
 * issued in one place and used from another.
 *
 * The surface columns hold the app's own `from` header — 'admin-web',
 * 'admin_mobile_app', 'frontend-web', 'customer_mobile_app' — which is the only
 * thing that separates a customer signing in on the website from one signing in
 * on the phone, since both are issued the same token name. Client-supplied and
 * therefore a label rather than evidence; the address is the part nobody can
 * forge.
 *
 * Every column is nullable and stays null for the tokens already in the table;
 * those fill themselves in on each token's next authenticated request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // 45 characters is the widest an IPv6 address with an embedded IPv4
            // suffix can be, and matches the sessions table's own column.
            $table->string('ip_address', 45)->nullable()->after('abilities');
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->string('last_used_ip', 45)->nullable()->after('user_agent');
            $table->text('last_used_user_agent')->nullable()->after('last_used_ip');

            // 32 is ample for the four values the apps send, and caps what an
            // unrecognised caller can store here.
            $table->string('client_surface', 32)->nullable()->after('last_used_user_agent');
            $table->string('last_used_surface', 32)->nullable()->after('client_surface');

            // The session list orders by recency and filters on idleness, so
            // this column is read on every page view.
            $table->index('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['last_used_at']);

            $table->dropColumn([
                'ip_address',
                'user_agent',
                'last_used_ip',
                'last_used_user_agent',
                'client_surface',
                'last_used_surface',
            ]);
        });
    }
};
