<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that whoever is signing up can actually read the mailbox they typed.
 *
 * Deliberately NOT password_reset_tokens, which is keyed by email and would collide:
 * a provider resetting their password while a signup is mid-flight on the same address
 * would overwrite one token with the other, and whichever flow checked second would
 * fail for no visible reason.
 *
 * Rows are short-lived — consumed by registration, or swept once stale — so the table
 * stays small and holds nothing that is not already in `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_otps', function (Blueprint $table) {
            $table->string('email')->primary();

            // Hashed, never stored in the clear: this table is a plaintext one-time
            // password for an account otherwise, and the codes are only six digits.
            $table->string('otp_hash');

            // Wrong guesses against THIS code. Six digits is a 1-in-a-million guess,
            // but unlimited attempts turn that into a few minutes of scripting.
            $table->unsignedTinyInteger('attempts')->default(0);

            // Set when the code is accepted; the token is what registration then
            // presents, so the verified state survives the page the OTP was typed on.
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_token_hash')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->index('created_at');     // for sweeping stale rows
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_otps');
    }
};
