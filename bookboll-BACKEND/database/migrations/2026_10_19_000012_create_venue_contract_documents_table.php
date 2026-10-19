<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signed landlord contracts, stored privately.
 *
 * These are commercial agreements between Bookboll and a landlord. They are
 * NOT images: they never go through the public upload service, never get a
 * public URL, and are written to the private disk under a randomised name, so
 * knowing a filename reveals nothing and guessing one reaches nothing.
 *
 * linked_at is what separates a real contract from an abandoned upload. A file
 * is uploaded before the agreement that cites it is saved, so until that save
 * succeeds it is temporary and expires — readmeAlamgir: "A failed agreement
 * save can leave only an expiring temporary upload, never a public orphan."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_contract_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();

            $table->string('disk', 32)->default('local');
            // Randomised server-side; the client never chooses where it lands.
            $table->string('path', 512)->unique();
            // Shown in the editor so staff recognise the file. Display only —
            // never used to build a path.
            $table->string('original_name', 255)->nullable();
            $table->string('mime', 96)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // The signature actually found in the bytes, which is what was
            // trusted — not the extension or the browser's content type.
            $table->string('verified_type', 16)->nullable();

            // Null while the upload is still temporary.
            $table->timestamp('linked_at')->nullable()->index();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamps();

            $table->index(['venue_id', 'linked_at'], 'venue_contracts_venue_linked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_contract_documents');
    }
};
