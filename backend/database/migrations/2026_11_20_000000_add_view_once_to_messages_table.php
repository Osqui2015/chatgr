<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a message to the ephemeral asset it carries.
 *
 * The asset keeps the single-use lifecycle while the message stores the
 * relationship, so the image can only be opened by the conversation
 * participants and never by the author.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('media_asset_id')
                ->nullable()
                ->unique()
                ->after('attachment_height')
                ->constrained('media_assets')
                ->nullOnDelete();
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->enum('type', ['text', 'image', 'gif', 'view_once'])
                ->default('text')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->enum('type', ['text', 'image', 'gif'])
                ->default('text')
                ->change();
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('media_asset_id');
        });
    }
};
