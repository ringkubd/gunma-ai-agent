<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Piku analytics — one lightweight row per meaningful Piku action so the
 * business can see: how many people Piku helped, how many orders it drove,
 * revenue attributed, which tools/intents fire, and doodle engagement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('piku_events')) {
            return;
        }

        Schema::create('piku_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);                 // doodle_message | compose | chat_opened |
                                                        // product_focus | tool_call | order_placed |
                                                        // checkout_opened | chip_click | login_opened
            $table->uuid('session_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('visitor_id', 64)->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('tool', 64)->nullable();
            $table->decimal('value', 12, 2)->nullable();  // revenue / order total when known
            $table->string('lang', 8)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['event', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piku_events');
    }
};
