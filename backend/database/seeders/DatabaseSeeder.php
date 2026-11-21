<?php

namespace Database\Seeders;

use App\Enums\ConversationType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the data the application needs to be usable right after installation.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@chat.local'],
            [
                'nickname' => 'Administrador',
                'password' => Hash::make('Admin123!'),
                'email_verified_at' => now(),
                'role' => UserRole::Admin,
                'status' => UserStatus::Offline,
            ],
        );

        Conversation::query()->firstOrCreate(
            ['type' => ConversationType::Global],
            ['title' => 'Chat General', 'last_message_at' => now()],
        );

        $this->command?->info("Admin creado: {$admin->email} / Admin123!");
    }
}
