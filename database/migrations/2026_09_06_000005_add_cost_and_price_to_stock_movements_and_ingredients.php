<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add cost and purchase price audit columns to stock_movements
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_price', 15, 2)->nullable()->after('qty')->comment('Harga beli per unit satuan transaksi');
            $table->decimal('total_price', 15, 2)->nullable()->after('unit_price')->comment('Total nilai pembelian (qty * unit_price)');
            $table->decimal('cost_before', 15, 4)->nullable()->after('total_price')->comment('Harga rata-rata per satuan pakai sebelum transaksi');
            $table->decimal('cost_after', 15, 4)->nullable()->after('cost_before')->comment('Harga rata-rata per satuan pakai setelah transaksi (Moving Average)');
        });

        // 2. Add last_purchase_price to ingredients
        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('last_purchase_price', 15, 2)->nullable()->after('harga')->comment('Harga beli terakhir per unit_beli');
        });

        // Backfill last_purchase_price with current initial harga
        DB::statement('UPDATE ingredients SET last_purchase_price = harga WHERE last_purchase_price IS NULL');
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('last_purchase_price');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'total_price', 'cost_before', 'cost_after']);
        });
    }
};
