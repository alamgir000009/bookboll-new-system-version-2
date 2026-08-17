<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align users + loyalty with the enhanced apps/admin Customers page
 * (CRM tags/blocks, marketing consent, per-country loyalty balances).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'crm_tags')) {
                    $table->text('crm_tags')->nullable();
                }
                if (! Schema::hasColumn('users', 'marketing_email')) {
                    $table->boolean('marketing_email')->default(false);
                }
                if (! Schema::hasColumn('users', 'marketing_sms')) {
                    $table->boolean('marketing_sms')->default(false);
                }
                if (! Schema::hasColumn('users', 'marketing_whatsapp')) {
                    $table->boolean('marketing_whatsapp')->default(false);
                }
            });
        }

        if (! Schema::hasTable('customer_loyalty')) {
            Schema::create('customer_loyalty', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id')->index();
                $table->unsignedBigInteger('country_id')->index();
                $table->unsignedInteger('points')->default(0);
                $table->unsignedInteger('lifetime_points')->default(0);
                $table->timestamps();

                $table->unique(['customer_id', 'country_id']);
                $table->foreign('customer_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_loyalty');

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $cols = ['crm_tags', 'marketing_email', 'marketing_sms', 'marketing_whatsapp'];
                $drop = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('users', $c)));
                if ($drop) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
