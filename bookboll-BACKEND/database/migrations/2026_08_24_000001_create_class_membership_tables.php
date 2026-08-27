<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership programs / subscribers / sessions (Supabase class_* parity).
 * Bigint PKs; unique uuid keeps the original Postgres ids for imports.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('class_programs')) {
            Schema::create('class_programs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->uuid('provider_id')->nullable()->index();
                $table->uuid('listing_id')->nullable()->index();
                $table->json('listing_ids')->nullable();
                $table->uuid('location_id')->nullable()->index();
                $table->unsignedBigInteger('venue_id')->nullable()->index();
                $table->uuid('owner_user_id')->nullable()->index(); // Supabase admin_users uuid
                $table->unsignedBigInteger('mapped_owner_user_id')->nullable()->index(); // Laravel users.id

                $table->string('name');
                $table->text('description')->nullable();
                $table->string('sport_category')->nullable();
                $table->string('coach_name')->nullable();
                $table->decimal('monthly_price', 12, 2)->default(0);
                $table->string('currency', 8)->default('AED');
                $table->unsignedInteger('sessions_per_week')->default(1);
                $table->unsignedInteger('session_duration_minutes')->default(60);
                $table->json('day_of_week_mask')->nullable();
                $table->string('session_time', 16)->nullable();
                $table->unsignedInteger('capacity')->default(12);
                $table->decimal('commission_percentage', 5, 2)->default(20);
                $table->string('status', 32)->default('active')->index();
                $table->string('photo_url', 1024)->nullable();
                $table->json('images')->nullable();
                $table->string('color', 32)->nullable();
                $table->integer('sort_order')->default(0);

                $table->string('billing_interval', 32)->default('monthly');
                $table->string('program_type', 32)->default('ongoing');
                $table->unsignedInteger('duration_weeks')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->unsignedInteger('bonus_months')->default(0);
                $table->unsignedInteger('free_trial_days')->default(14);
                $table->boolean('free_trial_enabled')->default(true);
                $table->unsignedInteger('free_trial_sessions')->default(0);
                $table->unsignedInteger('discount_months')->default(0);
                $table->decimal('joining_fee', 12, 2)->default(0);
                $table->boolean('is_draft')->default(false);

                $table->boolean('show_on_marketplace')->default(true);
                $table->boolean('show_on_provider_site')->default(true);
                $table->string('category', 64)->default('academy');
                $table->string('audience', 32)->nullable();
                $table->string('gender_policy', 32)->default('mixed');
                $table->json('signup_form')->nullable();

                $table->string('approval_status', 32)->default('approved')->index();
                $table->string('eligibility_status', 32)->default('eligible')->index();
                $table->text('admin_note')->nullable();
                $table->json('previous_snapshot')->nullable();
                $table->json('pending_content')->nullable();
                $table->timestamp('trial_terms_accepted_at')->nullable();
                $table->string('trial_terms_version')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->string('submitted_by_name')->nullable();

                $table->timestamps();

                $table->foreign('venue_id')->references('id')->on('venues')->nullOnDelete();
                $table->foreign('mapped_owner_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('class_subscriptions')) {
            Schema::create('class_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('program_id')->constrained('class_programs')->cascadeOnDelete();
                $table->uuid('customer_id')->nullable()->index();
                $table->string('customer_name')->nullable();
                $table->string('customer_email')->nullable()->index();

                $table->date('started_at')->nullable();
                $table->date('next_billing_at')->nullable()->index();
                $table->date('trial_ends_at')->nullable();
                $table->string('status', 32)->default('active')->index();
                $table->string('trial_status', 32)->default('in_trial')->index();
                $table->string('billing_interval', 32)->nullable();

                $table->decimal('monthly_amount', 12, 2)->default(0);
                $table->decimal('provider_payout', 12, 2)->default(0);
                $table->decimal('platform_fee', 12, 2)->default(0);
                $table->decimal('joining_fee', 12, 2)->default(0);

                $table->string('card_on_file_id')->nullable();
                $table->timestamp('first_charge_at')->nullable();
                $table->timestamp('cancelled_during_trial_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('paused_until')->nullable();
                $table->timestamp('payment_failed_at')->nullable();

                $table->uuid('country_id')->nullable();
                $table->unsignedBigInteger('wallet_id')->nullable()->index();
                $table->json('form_answers')->nullable();
                $table->boolean('is_comp')->default(false);
                $table->string('comp_reason')->nullable();
                $table->string('cancel_reason')->nullable();

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('class_sessions')) {
            Schema::create('class_sessions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('subscription_id')->constrained('class_subscriptions')->cascadeOnDelete();
                $table->foreignId('program_id')->constrained('class_programs')->cascadeOnDelete();
                $table->uuid('listing_id')->nullable()->index();
                $table->date('session_date')->index();
                $table->string('session_time', 16);
                $table->unsignedInteger('duration_minutes')->default(60);
                $table->string('status', 32)->default('scheduled')->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('class_subscriptions');
        Schema::dropIfExists('class_programs');
    }
};
