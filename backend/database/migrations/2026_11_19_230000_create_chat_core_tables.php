<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core chat schema: users profile extensions, presence, conversations, messages.
 *
 * Designed for millions of messages: every hot lookup path has a composite index
 * and message bodies are kept out of frequently joined tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('nickname', 32)->unique();
            $table->string('email', 190)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('avatar_path', 255)->nullable();
            $table->string('avatar_mime', 32)->nullable();
            $table->unsignedInteger('avatar_size')->nullable();
            $table->string('status', 16)->default('offline');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('role', 16)->default('user');
            $table->boolean('is_banned')->default(false);
            $table->boolean('is_muted')->default(false);
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['global', 'direct'])->default('direct');
            $table->string('title', 120)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'last_message_at']);
        });

        Schema::create('conversation_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'conversation_id']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('reply_to_id')->nullable();
            $table->text('body');
            $table->enum('type', ['text', 'image', 'gif'])->default('text');
            $table->string('attachment_path', 255)->nullable();
            $table->string('attachment_mime', 64)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->unsignedInteger('attachment_width')->nullable();
            $table->unsignedInteger('attachment_height')->nullable();
            $table->string('edit_token', 64)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id'], 'messages_conversation_id_id_index');
            $table->index(['conversation_id', 'created_at'], 'messages_conversation_created_index');
            $table->index(['user_id', 'created_at'], 'messages_user_created_index');
            $table->index('reply_to_id');
        });

        Schema::create('message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamps();

            $table->unique(['message_id', 'user_id', 'emoji']);
            $table->index(['message_id', 'emoji']);
        });

        Schema::create('message_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_user');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('users');
    }
};
