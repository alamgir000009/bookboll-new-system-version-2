<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Bookboll owes the landlord of a venue it operates, over time.
 *
 * Immutable and effective-dated: a new version never edits the one before it,
 * it supersedes it. Rent and revenue share are posted against service dates, so
 * rewriting an old row would silently restate months that have already been
 * reported. readmeAlamgir: "Do not overwrite historic versions... A renewal
 * must not mutate already-posted history."
 *
 * Two intervals per row, deliberately:
 *   • effective_from / effective_to — what the CONTRACT says, preserved exactly
 *     as agreed even after a successor cuts the term short.
 *   • the ACTIVE reporting interval, which is derived: a successor's start
 *     ends the predecessor the day before, unless the contract already ended
 *     earlier. Stored as superseded_on so reporting and the dashboard resolve
 *     the same interval instead of each computing its own.
 *
 * No agreement for a period means landlord cost is UNDEFINED for it, never
 * zero and never full retention — a gap is a question to answer, not a licence
 * to assume Bookboll keeps everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_operating_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            // Monotonic per venue; the collection version the editor sends back
            // as expected_version so a stale save is rejected rather than applied.
            $table->unsignedInteger('version');

            $table->enum('deal_type', ['fixed_rent', 'revenue_share', 'direct_operation']);
            $table->string('currency', 10);

            // Counterparty snapshot — kept on the row because the landlord's
            // record may change later and this is what was agreed.
            $table->unsignedBigInteger('landlord_id')->nullable();
            $table->string('landlord_name')->nullable();

            // Minor units, so a fractional month of rent lands on real money.
            $table->unsignedBigInteger('monthly_rent_minor')->nullable();
            // 0 through 100 inclusive; the landlord's share is the complement.
            $table->decimal('bookboll_share_percent', 6, 3)->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // Set when a successor starts: the last day this row actually
            // governed. Null while it is the latest.
            $table->date('superseded_on')->nullable();

            $table->string('revenue_basis', 48)->default('on_arrival_after_platform_fees');
            $table->string('rent_proration', 32)->default('calendar_days');

            $table->enum('change_type', ['initial', 'renewal', 'amendment']);
            $table->text('change_reason')->nullable();
            $table->unsignedBigInteger('supersedes_agreement_id')->nullable()->index();
            $table->unsignedBigInteger('contract_document_id')->nullable()->index();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            // Repeated saves return the original row instead of a second version.
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->unique(['venue_id', 'version'], 'venue_agreements_version_unique');
            $table->index(['venue_id', 'effective_from'], 'venue_agreements_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_operating_agreements');
    }
};
