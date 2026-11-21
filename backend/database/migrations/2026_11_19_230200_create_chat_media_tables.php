<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ephemeral media: "view once" images and permanent chat attachments.
 *
 * Ephemeral rows carry a single-use token and an expiry; the file and the row are
 * destroyed by a queued job as soon as the asset is consumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('disk', 32)->default('public');
            $table->string('path', 255);
            $table->string('mime', 64);
            $table->unsignedInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->enum('kind', ['attachment', 'ephemeral'])->default('attachment');
            $table->string('token_hash', 64)->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('view_count')->default(0);
            $table->timestamps();

            $table->index(['kind', 'expires_at']);
            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
