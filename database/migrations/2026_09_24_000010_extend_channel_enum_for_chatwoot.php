<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the channel enum to cover Chatwoot-origin channels.
        if (config('database.default') === 'mysql') {
            DB::statement("ALTER TABLE chat_sessions MODIFY COLUMN channel ENUM('web', 'admin', 'whatsapp', 'email', 'facebook', 'instagram', 'telegram', 'chatwoot') DEFAULT 'web'");
        }
    }

    public function down(): void
    {
        if (config('database.default') === 'mysql') {
            DB::statement("ALTER TABLE chat_sessions MODIFY COLUMN channel ENUM('web', 'admin', 'whatsapp', 'email') DEFAULT 'web'");
        }
    }
};
