<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conversation summaries for session restart context
        Schema::create('conversation_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->text('summary');
            $table->json('key_topics')->nullable();
            $table->string('sentiment', 20)->default('neutral')->comment('positive/neutral/negative');
            $table->boolean('follow_up_needed')->default(false);
            $table->timestamps();
        });

        // Customer preferences for personalized experience
        Schema::create('customer_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->unique();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->json('favorite_categories')->nullable();
            $table->text('dietary_notes')->nullable();
            $table->unsignedSmallInteger('family_size')->nullable();
            $table->string('budget_tier', 20)->default('medium')->comment('low/medium/high');
            $table->json('preferred_delivery_times')->nullable();
            $table->json('allergies')->nullable();
            $table->json('special_occasions')->nullable();
            $table->timestamp('last_analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_preferences');
        Schema::dropIfExists('conversation_summaries');
    }
};
