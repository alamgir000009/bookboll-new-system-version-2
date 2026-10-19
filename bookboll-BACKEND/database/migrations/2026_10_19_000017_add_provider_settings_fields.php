<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homes for the provider settings fields that had none.
 *
 * Settings → Business Profile showed Business Name, Phone and Description, and
 * read them off the signed-in user, where none of those columns exist. The form
 * was reading nothing and saving nowhere, which is why its save button said
 * updates were unavailable.
 *
 * `businesses.name` is the business's real name and already exists; `brand_name`
 * is the public website name, edited in My Website — the two are deliberately
 * different and stay different. Phone and description had nowhere at all.
 *
 * Personal notification preferences are per PERSON, not per business: two staff
 * on one business choose separately, so they live on users. Distinct from
 * `businesses.notification_templates`, which is the copy sent to customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('name');
            $table->text('description')->nullable()->after('phone');
        });

        Schema::table('users', function (Blueprint $table) {
            // Null means "nothing chosen yet", which is not the same as every
            // preference being off — defaults apply until someone decides.
            $table->json('notification_preferences')->nullable()->after('is_discoverable');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['phone', 'description']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
