<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-generated, AI-written product blurbs (per language) used by the Piku
 * doodle so it can talk about a product instantly, with no runtime LLM call.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('piku_product_blurbs')) {
            return;
        }

        Schema::create('piku_product_blurbs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('lang', 8);            // en | bn (Banglish) | hi
            $table->text('text');
            $table->string('source_hash', 40)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'lang']);
            $table->index('lang');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piku_product_blurbs');
    }
};
