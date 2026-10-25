<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\StockMovement;
use App\Services\AccountingService;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Pastikan kolom is_unlimited_mode ada di tabel transactions
        if (!Schema::hasColumn('transactions', 'is_unlimited_mode')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->boolean('is_unlimited_mode')->default(false)->after('business_id')->index();
            });
        }

        try {
            // 2. Tandai seluruh transaksi yang dibuat oleh bisnis dengan mode unlimited
            DB::statement("
                UPDATE transactions t
                JOIN businesses b ON t.business_id = b.id
                SET t.is_unlimited_mode = 1
                WHERE b.package_type = 'unlimited'
                   OR b.is_unlimited_coins = 1
                   OR (b.coins_per_transaction IS NOT NULL AND b.coins_per_transaction <= 0)
            ");
        } catch (\Throwable $e) {}

        try {
            // 3. Tandai seluruh transaksi historis yang dibuat sebelum bisnis beralih ke premium / tanpa potongan koin
            // Untuk bisnis yang sekarang premium (seperti bisnis yang dibuat dalam mode unlimited lalu diupgrade),
            // transaksi yang tidak memiliki catatan potongan koin adalah transaksi masa unlimited.
            DB::statement("
                UPDATE transactions t
                SET t.is_unlimited_mode = 1
                WHERE t.is_unlimited_mode = 0
                  AND NOT EXISTS (
                      SELECT 1 FROM coin_transactions ct
                      WHERE ct.business_id = t.business_id
                        AND ct.order_number = t.order_number
                  )
            ");
        } catch (\Throwable $e) {}

        try {
            // 4. Bersihkan mutasi stok SALE_USAGE yang sempat tercatat pada transaksi mode unlimited
            $unlimitedTxIds = Transaction::where('is_unlimited_mode', true)->pluck('id');
            if ($unlimitedTxIds->isNotEmpty()) {
                StockMovement::whereIn('transaction_id', $unlimitedTxIds)
                    ->where('type', 'SALE_USAGE')
                    ->delete();
            }
        } catch (\Throwable $e) {}

        try {
            // 5. Rebuild Jurnal Penjualan untuk transaksi mode unlimited agar HPP & Persediaan = 0
            $unlimitedTxs = Transaction::where('is_unlimited_mode', true)->get();
            foreach ($unlimitedTxs as $trx) {
                AccountingService::buildAndRecordSalesJournal($trx);
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No destructive reverse needed
    }
};
