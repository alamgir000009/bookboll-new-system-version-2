<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a booking row actually IS.
 *
 * Three different things share this table and were told apart by guesswork:
 *
 *   • a real marketplace sale;
 *   • a provider reserving a slot for their own customer, entered with
 *     payment_skipped — never an unpaid sale, and never Bookboll revenue;
 *   • a membership session placeholder on the calendar.
 *
 * Every one of them carries origin `bookboll_web` in this database, which is
 * exactly the inference readmeAlamgir forbids: "Do not... pretend they were
 * made through the marketplace because their old origin is blank or
 * bookboll_web."
 *
 * Nothing is deleted and no calendar occupancy, booking id or history changes.
 * The rows stay exactly as they are; they just stop lying about what they are.
 */
return new class extends Migration
{
    public const PURPOSE_SALE  = 'marketplace_sale';
    public const PURPOSE_BLOCK = 'provider_slot_block';
    public const PURPOSE_SESSION = 'membership_session';
    /** A block carrying a real posted payment: neither, until a human looks. */
    public const PURPOSE_REVIEW = 'needs_review';

    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'record_purpose')) {
                $table->string('record_purpose', 32)->nullable()->index();
            }
        });

        // Membership placeholders first — they are a distinct kind of
        // calendar row, not a sale and not a provider block.
        DB::table('bookings')
            ->whereNull('record_purpose')
            ->where('origin', 'class_program')
            ->update(['record_purpose' => self::PURPOSE_SESSION]);

        /*
         * Provider slot blocks. A block that nonetheless carries a real posted
         * payment is MIXED EVIDENCE: the document says that needs manual
         * review, not silent suppression of an actual ledger entry. So those
         * are marked for review rather than classified either way.
         */
        $isBlock = fn ($q) => $q->where(function ($w) {
            $w->where('payment_method', 'payment_skipped')
                ->orWhere('payment_status', 'payment_skipped');
        });

        $hasPosted = fn ($q) => $q->whereExists(function ($e) {
            $e->selectRaw('1')->from('payments')
                ->whereColumn('payments.booking_id', 'bookings.id')
                ->where('payments.status', 'paid')
                ->where('payments.amount', '>', 0);
        });

        DB::table('bookings')->whereNull('record_purpose')
            ->where($isBlock)->where($hasPosted)
            ->update(['record_purpose' => self::PURPOSE_REVIEW]);

        DB::table('bookings')->whereNull('record_purpose')
            ->where($isBlock)
            ->update(['record_purpose' => self::PURPOSE_BLOCK]);

        // Everything else is an ordinary sale.
        DB::table('bookings')->whereNull('record_purpose')
            ->update(['record_purpose' => self::PURPOSE_SALE]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'record_purpose')) {
                $table->dropColumn('record_purpose');
            }
        });
    }
};
