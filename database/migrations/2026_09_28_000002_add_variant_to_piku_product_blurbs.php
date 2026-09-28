<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Add blurb variety (3 variants/lang) so the doodle never repeats itself. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('piku_product_blurbs')) return;
        Schema::table('piku_product_blurbs', function (Blueprint $table) {
            if (! Schema::hasColumn('piku_product_blurbs', 'variant')) {
                $table->unsignedTinyInteger('variant')->default(0)->after('lang');
                $table->unique(['product_id', 'lang', 'variant']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('piku_product_blurbs', function (Blueprint $table) {
            if (Schema::hasColumn('piku_product_blurbs', 'variant')) {
                $table->dropUnique(['product_id', 'lang', 'variant']);
                $table->dropColumn('variant');
            }
        });
    }
};
