<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('visitor_id', 64)->index();
            $table->string('customer_name', 255)->nullable();
            $table->enum('channel', ['web', 'admin', 'whatsapp'])->default('web');
            $table->enum('status', ['active', 'ended', 'archived'])->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['visitor_id', 'status']);
            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
