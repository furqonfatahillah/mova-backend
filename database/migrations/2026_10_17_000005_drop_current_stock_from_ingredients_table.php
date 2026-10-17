<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drop redundant current_stock from ingredients (Table 1 - Master Bahan).
     * Saldo Berjalan (current_stock) is exclusively tracked per-outlet in Table 3 (outlet_ingredients).
     */
    public function up(): void
    {
        if (Schema::hasTable('ingredients') && Schema::hasColumn('ingredients', 'current_stock')) {
            Schema::table('ingredients', function (Blueprint $table) {
                $table->dropColumn('current_stock');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ingredients') && !Schema::hasColumn('ingredients', 'current_stock')) {
            Schema::table('ingredients', function (Blueprint $table) {
                $table->decimal('current_stock', 15, 3)->default(0)->after('last_purchase_price');
            });
        }
    }
};
