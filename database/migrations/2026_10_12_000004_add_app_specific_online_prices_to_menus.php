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
        if (Schema::hasTable('menus')) {
            Schema::table('menus', function (Blueprint $table) {
                if (!Schema::hasColumn('menus', 'price_grab')) {
                    $table->decimal('price_grab', 15, 2)->nullable()->after('price_online');
                }
                if (!Schema::hasColumn('menus', 'price_gofood')) {
                    $table->decimal('price_gofood', 15, 2)->nullable()->after('price_grab');
                }
                if (!Schema::hasColumn('menus', 'price_shopeefood')) {
                    $table->decimal('price_shopeefood', 15, 2)->nullable()->after('price_gofood');
                }
            });
        }

        if (Schema::hasTable('outlet_menus')) {
            Schema::table('outlet_menus', function (Blueprint $table) {
                if (!Schema::hasColumn('outlet_menus', 'price_grab')) {
                    $table->decimal('price_grab', 15, 2)->nullable()->after('price_online');
                }
                if (!Schema::hasColumn('outlet_menus', 'price_gofood')) {
                    $table->decimal('price_gofood', 15, 2)->nullable()->after('price_grab');
                }
                if (!Schema::hasColumn('outlet_menus', 'price_shopeefood')) {
                    $table->decimal('price_shopeefood', 15, 2)->nullable()->after('price_gofood');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Schema::table('menus', function (Blueprint $table) {
                $columns = ['price_grab', 'price_gofood', 'price_shopeefood'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('menus', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('outlet_menus')) {
            Schema::table('outlet_menus', function (Blueprint $table) {
                $columns = ['price_grab', 'price_gofood', 'price_shopeefood'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('outlet_menus', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
