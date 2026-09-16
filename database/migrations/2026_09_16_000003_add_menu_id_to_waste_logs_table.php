<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_logs', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->nullable()->change();
            $table->string('item_type', 20)->default('INGREDIENT')->after('date')->comment('INGREDIENT atau MENU');
            $table->foreignId('menu_id')->nullable()->after('ingredient_id')->constrained('menus')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('waste_logs', function (Blueprint $table) {
            $table->dropForeign(['menu_id']);
            $table->dropColumn(['item_type', 'menu_id']);
        });
    }
};
