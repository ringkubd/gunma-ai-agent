<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'email' to the channel enum. 
        // Note: Changing enums in Laravel/MySQL can be tricky. 
        // A safe way is to change it to a string temporarily or use a raw query.
        
        if (config('database.default') === 'mysql') {
            DB::statement("ALTER TABLE chat_sessions MODIFY COLUMN channel ENUM('web', 'admin', 'whatsapp', 'email') DEFAULT 'web'");
        } else {
            // Fallback for sqlite/others
            Schema::table('chat_sessions', function (Blueprint $table) {
                $table->string('channel')->default('web')->change();
            });
        }
    }

    public function down(): void
    {
        if (config('database.default') === 'mysql') {
            DB::statement("ALTER TABLE chat_sessions MODIFY COLUMN channel ENUM('web', 'admin', 'whatsapp') DEFAULT 'web'");
        }
    }
};
