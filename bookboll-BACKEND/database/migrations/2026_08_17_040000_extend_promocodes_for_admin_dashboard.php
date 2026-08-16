<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align promocodes with the enhanced apps/admin Promocodes page
 * (recipient, multi-scope, audience targeting, redemption ledger).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('promocodes')) {
            Schema::table('promocodes', function (Blueprint $table) {
                if (! Schema::hasColumn('promocodes', 'recipient')) {
                    $table->string('recipient', 20)->default('bookboll')->index();
                }
                if (! Schema::hasColumn('promocodes', 'creator_role')) {
                    $table->string('creator_role', 40)->default('super_admin');
                }
                if (! Schema::hasColumn('promocodes', 'owner_user_id')) {
                    $table->unsignedBigInteger('owner_user_id')->nullable()->index();
                }
                if (! Schema::hasColumn('promocodes', 'applicable_to')) {
                    $table->string('applicable_to', 30)->default('all')->index();
                }
                if (! Schema::hasColumn('promocodes', 'location_id')) {
                    $table->unsignedBigInteger('location_id')->nullable()->index();
                }
                if (! Schema::hasColumn('promocodes', 'owner_user_ids')) {
                    $table->json('owner_user_ids')->nullable();
                }
                if (! Schema::hasColumn('promocodes', 'location_ids')) {
                    $table->json('location_ids')->nullable();
                }
                if (! Schema::hasColumn('promocodes', 'listing_ids')) {
                    $table->json('listing_ids')->nullable();
                }
                if (! Schema::hasColumn('promocodes', 'program_ids')) {
                    $table->json('program_ids')->nullable();
                }
                if (! Schema::hasColumn('promocodes', 'audience')) {
                    $table->string('audience', 20)->default('all');
                }
                if (! Schema::hasColumn('promocodes', 'target_emails')) {
                    $table->json('target_emails')->nullable();
                }
                if (! Schema::hasColumn('promocodes', 'per_customer_limit')) {
                    $table->unsignedInteger('per_customer_limit')->default(0);
                }
            });
        }

        if (! Schema::hasTable('promocode_redemptions')) {
            Schema::create('promocode_redemptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('promocode_id')->nullable()->constrained('promocodes')->nullOnDelete();
                $table->string('code')->default('');
                $table->string('customer_email')->default('')->index();
                $table->string('customer_name')->default('');
                $table->string('source_type', 40)->default('');
                $table->string('source_id')->nullable();
                $table->decimal('order_amount', 12, 2)->default(0);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->string('currency', 10)->default('AED');
                $table->string('recipient', 20)->default('');
                $table->unsignedBigInteger('owner_user_id')->nullable()->index();
                $table->unsignedBigInteger('country_id')->nullable();
                $table->timestamp('redeemed_at')->nullable();
                $table->timestamps();

                $table->index('promocode_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promocode_redemptions');

        if (Schema::hasTable('promocodes')) {
            Schema::table('promocodes', function (Blueprint $table) {
                $cols = [
                    'recipient', 'creator_role', 'owner_user_id', 'applicable_to', 'location_id',
                    'owner_user_ids', 'location_ids', 'listing_ids', 'program_ids',
                    'audience', 'target_emails', 'per_customer_limit',
                ];
                $drop = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('promocodes', $c)));
                if ($drop) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
