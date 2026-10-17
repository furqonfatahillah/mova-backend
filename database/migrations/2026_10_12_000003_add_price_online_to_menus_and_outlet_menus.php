<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('menus') && !Schema::hasColumn('menus', 'price_online')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->decimal('price_online', 15, 2)->nullable()->after('price');
            });
        }

        if (Schema::hasTable('outlet_menus') && !Schema::hasColumn('outlet_menus', 'price_online')) {
            Schema::table('outlet_menus', function (Blueprint $table) {
                $table->decimal('price_online', 15, 2)->nullable()->after('price');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('menus') && Schema::hasColumn('menus', 'price_online')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->dropColumn('price_online');
            });
        }

        if (Schema::hasTable('outlet_menus') && Schema::hasColumn('outlet_menus', 'price_online')) {
            Schema::table('outlet_menus', function (Blueprint $table) {
                $table->dropColumn('price_online');
            });
        }
    }
};
