<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cleanly separate the 3 tables:
     * 1. ingredients -> Master Bahan (removes saldo awal columns from master table)
     * 2. ingredient_initial_balances -> Saldo Awal per Cabang
     * 3. outlet_ingredients -> Saldo Berjalan & Par Level Cabang
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('ingredients', 'stok_awal')) {
                $columnsToDrop[] = 'stok_awal';
            }
            if (Schema::hasColumn('ingredients', 'saldo_awal_nominal')) {
                $columnsToDrop[] = 'saldo_awal_nominal';
            }
            if (Schema::hasColumn('ingredients', 'tanggal_saldo_awal')) {
                $columnsToDrop[] = 'tanggal_saldo_awal';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            if (!Schema::hasColumn('ingredients', 'stok_awal')) {
                $table->decimal('stok_awal', 15, 3)->default(0)->after('last_purchase_price');
            }
            if (!Schema::hasColumn('ingredients', 'saldo_awal_nominal')) {
                $table->decimal('saldo_awal_nominal', 18, 2)->default(0)->after('current_stock');
            }
            if (!Schema::hasColumn('ingredients', 'tanggal_saldo_awal')) {
                $table->date('tanggal_saldo_awal')->nullable()->after('saldo_awal_nominal');
            }
        });
    }
};
