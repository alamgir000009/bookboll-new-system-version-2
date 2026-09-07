<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's acceptance of the free-trial terms for a membership programme
 * (Postgres: public.provider_trial_agreements).
 *
 * Recorded once, when a programme with a free trial is first submitted. The
 * commission and trial length AT THAT MOMENT are stamped on the row so a later
 * change to either can't rewrite what the provider agreed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provider_trial_agreements')) {
            return;
        }

        Schema::create('provider_trial_agreements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('program_id')->constrained('class_programs')->cascadeOnDelete();
            $table->unsignedBigInteger('owner_user_id')->nullable()->index();
            $table->string('terms_version', 40)->nullable();
            $table->decimal('commission_at_acceptance', 6, 2)->nullable();
            $table->unsignedInteger('trial_days_at_acceptance')->nullable();
            $table->timestamps();

            $table->index(['program_id', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_trial_agreements');
    }
};
