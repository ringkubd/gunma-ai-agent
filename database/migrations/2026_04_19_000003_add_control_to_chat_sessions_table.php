<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('chat_sessions', 'is_ai_enabled')) {
                $table->boolean('is_ai_enabled')->default(true)->after('status');
            }
            if (!Schema::hasColumn('chat_sessions', 'assigned_agent_id')) {
                $table->string('assigned_agent_id')->nullable()->after('is_ai_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropColumn(['is_ai_enabled', 'assigned_agent_id']);
        });
    }
};
