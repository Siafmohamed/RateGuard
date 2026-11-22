<?php

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

            // The WordPress post id is kept (and exposed) as-is, so the API
            // contract - and therefore React keys and /blog/{slug} links - stay
            // identical to what the REST gateway used to return.
            $table->unsignedBigInteger('wp_id')->unique();

            $table->string('slug')->unique();
            $table->string('title');
            $table->text('excerpt');
            $table->longText('content_html');
            $table->string('author')->nullable();
            $table->text('featured_image_url')->nullable();

            // Same shapes the content gateway already emits:
            // categories = list<{id, slug, name}>, seo = {title, description}|null.
            $table->json('categories')->nullable();
            $table->json('seo')->nullable();

            $table->string('status')->default('publish');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->timestamps();

            $table->index('published_at');
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
