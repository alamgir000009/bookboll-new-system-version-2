<?php

use App\Models\Post;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->index();
            $table->string('slug')->unique()->index();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->enum('status', [Post::DRAFT, Post::PUBLISHED])->default(Post::DRAFT)->index();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('post_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('featured_image')->nullable();
            $table->json('tags')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->boolean('featured')->default(false);
            $table->string('read_time')->nullable()->default('5 min read');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
