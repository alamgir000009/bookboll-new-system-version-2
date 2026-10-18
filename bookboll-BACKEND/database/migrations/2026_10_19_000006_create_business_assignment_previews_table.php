<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reviewed half of moving an account into a business.
 *
 * Assigning someone is not a single click: the server works out what the move
 * would do — which inherited permissions disappear, what blocks it — an admin
 * reads that, and only then commits. This table is what the admin actually
 * confirmed, so the commit can prove it is applying the same move that was
 * shown rather than whatever the request now says.
 *
 * readmeAlamgir: the token must "bind actor, source and destination, selected
 * policy/range, included row IDs and versions, preview sums and expiry",
 * "block replay across venues/businesses/users", and persist idempotency keys
 * "so lost responses/retries return the original completed operation, not a
 * second migration".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_assignment_previews', function (Blueprint $table) {
            $table->id();
            // Opaque and unguessable; the only thing the client ever holds.
            $table->string('token', 80)->unique();

            // Bound parties. A token minted for one business, actor or target
            // cannot be replayed against another, because all three are checked
            // against the request at commit.
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_user_id')->index();
            $table->unsignedBigInteger('target_user_id')->index();

            // The reviewed policy.
            $table->string('role', 40);
            $table->enum('scope', ['all', 'selected'])->default('all');
            $table->json('location_ids')->nullable();

            // What the admin was shown. Recomputed at commit and compared, so a
            // move whose circumstances changed is rejected rather than applied
            // on the strength of a stale review.
            $table->json('warnings')->nullable();
            $table->json('blockers')->nullable();
            $table->string('state_hash', 64);

            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();

            // Set when the move commits. Unique, so a retried request returns
            // the original membership instead of making a second one.
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->unsignedBigInteger('membership_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_assignment_previews');
    }
};
