<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who uploaded each file.
 *
 * POST /uploads/image stored files and handed back a URL without recording
 * anything, so the server could tell that a submitted photo was on our disk but
 * not whose it was. Those URLs are public — they appear in pages, get shared and
 * sit in browser history — so one account could illustrate its own event with a
 * photo another account uploaded. It also made three other things impossible:
 * deleting a user's files when the account goes, finding files nothing points at
 * any more, and applying any per-account limit at all.
 *
 * One row per stored file, keyed by its path on the disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            // The uploader. Cascades so deleting an account takes its upload
            // records with it; the Upload model deletes the file alongside the
            // row, so the bytes go too.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('disk', 32)->default('public');
            // The path is the identity: a submitted URL is reduced to this to
            // find its owner, so it must be unique. 512 keeps it inside the
            // index key limit on utf8mb4.
            $table->string('path', 512)->unique();
            $table->string('folder', 32)->index();
            $table->string('mime', 96)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('original_name', 255)->nullable();
            $table->timestamps();

            // "What did this account upload, newest first" and the orphan sweep.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
