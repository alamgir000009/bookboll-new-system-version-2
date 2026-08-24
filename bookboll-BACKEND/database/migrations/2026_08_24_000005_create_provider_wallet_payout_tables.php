<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider payout rails — mirrors Supabase public.provider_wallets,
 * withdrawal_requests, wallet_transactions (final column set, UUID PKs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('provider_wallets')) {
            Schema::create('provider_wallets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('owner_user_id')->index();
                $table->decimal('available_balance', 14, 2)->default(0);
                $table->decimal('pending_balance', 14, 2)->default(0);
                $table->decimal('lifetime_earnings', 14, 2)->default(0);
                $table->decimal('lifetime_withdrawn', 14, 2)->default(0);
                $table->string('currency', 16)->default('AED');
                $table->unsignedInteger('hold_days')->default(7);
                $table->string('setup_method', 64)->nullable();
                $table->string('setup_status', 64)->nullable()->index();
                $table->timestamp('setup_completed_at')->nullable();
                $table->uuid('country_id')->nullable()->index();
                $table->string('payout_bank_country', 8)->nullable();
                $table->text('payout_bank_name')->nullable();
                $table->text('payout_account_holder')->nullable();
                $table->text('payout_iban')->nullable();
                $table->text('payout_account_number')->nullable();
                $table->text('payout_routing_number')->nullable();
                $table->text('payout_swift')->nullable();
                $table->string('payout_account_type', 64)->nullable();
                $table->boolean('is_current')->default(true)->index();
                $table->string('status', 32)->default('current');
                $table->string('purpose', 32)->default('subscription')->index();
                $table->text('kyc_business_name')->nullable();
                $table->text('kyc_registration_no')->nullable();
                $table->text('kyc_address')->nullable();
                $table->text('kyc_contact')->nullable();
                $table->text('kyc_doc_url')->nullable();
                $table->timestamp('kyc_submitted_at')->nullable();
                $table->timestamp('kyc_reviewed_at')->nullable();
                $table->uuid('kyc_reviewed_by')->nullable();
                $table->text('kyc_rejection_reason')->nullable();
                $table->boolean('payout_paused')->default(false);
                $table->timestamp('payout_paused_at')->nullable();
                $table->text('payout_pause_reason')->nullable();
                $table->timestamps();

                $table->index(['owner_user_id', 'country_id', 'purpose'], 'pw_owner_country_purpose');
            });
        }

        if (! Schema::hasTable('withdrawal_requests')) {
            Schema::create('withdrawal_requests', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('owner_user_id')->index();
                $table->decimal('amount', 14, 2);
                $table->string('currency', 16)->default('AED');
                $table->string('payout_method', 64)->default('bank');
                $table->text('payout_details')->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->string('processed_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('country_id')->nullable()->index();
                $table->uuid('wallet_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('owner_user_id')->index();
                $table->string('type', 64)->default('booking_online');
                $table->string('direction', 16)->default('credit');
                $table->decimal('amount', 14, 2)->default(0);
                $table->decimal('provider_share', 14, 2)->default(0);
                $table->decimal('platform_fee', 14, 2)->default(0);
                $table->uuid('source_id')->nullable();
                $table->text('source_label')->nullable();
                $table->text('description')->nullable();
                $table->string('status', 32)->default('completed');
                $table->string('currency', 16)->default('AED');
                $table->date('available_on')->nullable();
                $table->unsignedInteger('hold_days_snapshot')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->uuid('country_id')->nullable()->index();
                $table->uuid('wallet_id')->nullable()->index();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('provider_wallets');
    }
};
