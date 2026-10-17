<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add current_stock column to outlet_ingredients
        if (Schema::hasTable('outlet_ingredients')) {
            Schema::table('outlet_ingredients', function (Blueprint $table) {
                if (!Schema::hasColumn('outlet_ingredients', 'current_stock')) {
                    $table->decimal('current_stock', 15, 3)->default(0)->after('stok_awal');
                    $table->index(['outlet_id', 'ingredient_id', 'current_stock'], 'idx_oi_out_ing_stock');
                }
            });
        }

        // 2. Add current_stock column to ingredients
        if (Schema::hasTable('ingredients')) {
            Schema::table('ingredients', function (Blueprint $table) {
                if (!Schema::hasColumn('ingredients', 'current_stock')) {
                    $table->decimal('current_stock', 15, 3)->default(0)->after('stok_awal');
                    $table->index(['business_id', 'current_stock'], 'idx_ing_biz_stock');
                }
            });
        }

        // 3. Initialize current_stock for all existing rows accurately based on stok_awal + net movements
        try {
            // A. Update outlet_ingredients
            DB::statement("
                UPDATE outlet_ingredients oi
                LEFT JOIN (
                    SELECT ingredient_id, outlet_id,
                           SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty
                    FROM stock_movements
                    WHERE type != 'INITIAL'
                    GROUP BY ingredient_id, outlet_id
                ) sm ON oi.ingredient_id = sm.ingredient_id AND oi.outlet_id = sm.outlet_id
                SET oi.current_stock = ROUND(COALESCE(oi.stok_awal, 0) + COALESCE(sm.net_qty, 0), 3)
            ");

            // B. Update ingredients
            DB::statement("
                UPDATE ingredients ing
                LEFT JOIN (
                    SELECT ingredient_id,
                           SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty
                    FROM stock_movements
                    WHERE type != 'INITIAL'
                    GROUP BY ingredient_id
                ) sm ON ing.id = sm.ingredient_id
                SET ing.current_stock = ROUND(COALESCE(ing.stok_awal, 0) + COALESCE(sm.net_qty, 0), 3)
            ");
        } catch (\Throwable $e) {
            // Log or fallback if raw multi-table update is not supported by driver
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('outlet_ingredients') && Schema::hasColumn('outlet_ingredients', 'current_stock')) {
            Schema::table('outlet_ingredients', function (Blueprint $table) {
                $table->dropIndex('idx_oi_out_ing_stock');
                $table->dropColumn('current_stock');
            });
        }

        if (Schema::hasTable('ingredients') && Schema::hasColumn('ingredients', 'current_stock')) {
            Schema::table('ingredients', function (Blueprint $table) {
                $table->dropIndex('idx_ing_biz_stock');
                $table->dropColumn('current_stock');
            });
        }
    }
};
