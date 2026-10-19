<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moving a venue to a business, and the optional reconciliation of its history.
 *
 * The move itself is ordinary. The reconciliation is not: it restates what
 * already-collected money MEANT — a paid deposit becomes Bookboll's fee, and a
 * pending arrival balance becomes cash the provider collected directly. That is
 * a reviewed human assertion about the past, not a payment, so readmeAlamgir
 * insists it creates "no Bookboll receipt, provider payable, provider wallet
 * credit or payout", and that the original records survive it.
 *
 * Hence three tables. A preview records exactly what an admin was shown and is
 * the only thing a commit will act on. A transfer records the batch. An
 * allocation records, per payment, what it was before and what it became — so
 * the reconciliation can be investigated, explained, or reversed later without
 * guessing what it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();

            // Where it came from, captured at the time — the venue's own
            // columns will have moved on by the time anyone reads this.
            $table->unsignedBigInteger('from_business_id')->nullable();
            $table->unsignedBigInteger('from_owner_user_id')->nullable();
            $table->unsignedBigInteger('to_business_id');

            $table->date('effective_from');
            $table->enum('history_mode', ['preserve', 'legacy_deposit_fee'])->default('preserve');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();

            // The batch every reconciled payment points back to.
            $table->string('batch_id', 64)->unique();
            // Legacy reconciliation only happens when an admin explicitly says
            // so, having seen the counts. Recorded as the assertion it is.
            $table->boolean('acknowledged_legacy_cash')->default(false);
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->timestamp('asserted_at')->nullable();

            $table->timestamps();
        });

        Schema::create('venue_transfer_previews', function (Blueprint $table) {
            $table->id();
            $table->string('token', 80)->unique();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_user_id')->index();
            $table->unsignedBigInteger('business_id');

            $table->date('effective_from');
            $table->enum('history_mode', ['preserve', 'legacy_deposit_fee'])->default('preserve');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();

            // What the admin was shown. Recomputed at commit and compared.
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('pending_arrival_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('landlord_obligation_count')->default(0);
            $table->json('totals')->nullable();
            $table->json('warnings')->nullable();
            $table->json('blockers')->nullable();
            // The payment rows the preview counted, so a commit acts on exactly
            // those and a changed set is refused rather than silently widened.
            $table->json('payment_ids')->nullable();
            $table->string('state_hash', 64);

            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->unsignedBigInteger('transfer_id')->nullable();

            $table->timestamps();
        });

        Schema::create('venue_transfer_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('venue_transfers')->cascadeOnDelete();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();

            // deposit_to_fee | provider_direct_cash
            $table->string('kind', 32)->index();
            $table->string('currency', 10)->nullable();
            $table->unsignedBigInteger('amount_minor')->default(0);

            // Immutable before/after, so nothing has to be reconstructed later.
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            // Who actually took the money, when it was not Bookboll. Separate
            // columns rather than a new `method` enum value: the enum governs
            // every live payment, and a reconciliation classification is a
            // different fact from how a payment was taken.
            if (! Schema::hasColumn('payments', 'collection_method')) {
                $table->string('collection_method', 32)->nullable()->index();
            }
            if (! Schema::hasColumn('payments', 'collected_by_business_id')) {
                $table->unsignedBigInteger('collected_by_business_id')->nullable()->index();
            }
            if (! Schema::hasColumn('payments', 'reconciliation_batch_id')) {
                $table->string('reconciliation_batch_id', 64)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            foreach (['collection_method', 'collected_by_business_id', 'reconciliation_batch_id'] as $c) {
                if (Schema::hasColumn('payments', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::dropIfExists('venue_transfer_allocations');
        Schema::dropIfExists('venue_transfer_previews');
        Schema::dropIfExists('venue_transfers');
    }
};
