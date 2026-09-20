<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add per-outlet moving average harga and last purchase price to outlet_ingredients
        Schema::table('outlet_ingredients', function (Blueprint $table) {
            if (!Schema::hasColumn('outlet_ingredients', 'harga')) {
                $table->decimal('harga', 15, 2)->nullable()->after('stok_min')->comment('Harga moving average beli per unit_beli khusus cabang ini');
            }
            if (!Schema::hasColumn('outlet_ingredients', 'last_purchase_price')) {
                $table->decimal('last_purchase_price', 15, 2)->nullable()->after('harga')->comment('Harga beli/transfer terakhir khusus cabang ini');
            }
        });

        // 2. Add per-outlet cost_price to outlet_menus for retail direct items
        Schema::table('outlet_menus', function (Blueprint $table) {
            if (!Schema::hasColumn('outlet_menus', 'cost_price')) {
                $table->decimal('cost_price', 15, 2)->nullable()->after('price')->comment('Harga modal pokok retail khusus cabang ini');
            }
        });

        // 3. Backfill existing outlet_ingredients with current master harga & last_purchase_price
        try {
            DB::statement("
                UPDATE outlet_ingredients oi
                JOIN ingredients i ON oi.ingredient_id = i.id
                SET oi.harga = i.harga,
                    oi.last_purchase_price = COALESCE(i.last_purchase_price, i.harga)
                WHERE oi.harga IS NULL
            ");
        } catch (\Throwable $e) {}

        // 4. Backfill existing outlet_menus with current master cost_price
        try {
            DB::statement("
                UPDATE outlet_menus om
                JOIN menus m ON om.menu_id = m.id
                SET om.cost_price = m.cost_price
                WHERE om.cost_price IS NULL AND m.cost_price IS NOT NULL
            ");
        } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        Schema::table('outlet_ingredients', function (Blueprint $table) {
            if (Schema::hasColumn('outlet_ingredients', 'last_purchase_price')) {
                $table->dropColumn('last_purchase_price');
            }
            if (Schema::hasColumn('outlet_ingredients', 'harga')) {
                $table->dropColumn('harga');
            }
        });

        Schema::table('outlet_menus', function (Blueprint $table) {
            if (Schema::hasColumn('outlet_menus', 'cost_price')) {
                $table->dropColumn('cost_price');
            }
        });
    }
};
