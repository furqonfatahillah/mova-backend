<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drop last_purchase_price and stok_min from ingredients (Table 1 - Master Bahan).
     * Both par level (stok_min) and last PO purchase price (last_purchase_price)
     * are strictly branch-level operational data and already tracked in Table 3 (outlet_ingredients).
     */
    public function up(): void
    {
        if (Schema::hasTable('ingredients')) {
            $columnsToDrop = [];
            if (Schema::hasColumn('ingredients', 'last_purchase_price')) {
                $columnsToDrop[] = 'last_purchase_price';
            }
            if (Schema::hasColumn('ingredients', 'stok_min')) {
                $columnsToDrop[] = 'stok_min';
            }

            if (!empty($columnsToDrop)) {
                Schema::table('ingredients', function (Blueprint $table) use ($columnsToDrop) {
                    $table->dropColumn($columnsToDrop);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ingredients')) {
            Schema::table('ingredients', function (Blueprint $table) {
                if (!Schema::hasColumn('ingredients', 'last_purchase_price')) {
                    $table->decimal('last_purchase_price', 15, 2)->nullable()->after('harga');
                }
                if (!Schema::hasColumn('ingredients', 'stok_min')) {
                    $table->decimal('stok_min', 15, 3)->default(0)->after('last_purchase_price');
                }
            });
        }
    }
};
