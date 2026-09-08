<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropUnique('outlets_code_unique');
            $table->unique(['business_id', 'code']);
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropUnique('ingredients_code_unique');
            $table->unique(['business_id', 'code']);
        });

        Schema::table('menus', function (Blueprint $table) {
            $table->dropUnique('menus_code_unique');
            $table->unique(['business_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'code']);
            $table->unique('code');
        });

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'code']);
            $table->unique('code');
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'code']);
            $table->unique('code');
        });
    }
};
