<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Services\AccountingService;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('transactions', 'is_unlimited_mode')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->boolean('is_unlimited_mode')->default(false)->after('business_id')->index();
            });
        }

        // Backfill: transaksi yang dibuat oleh bisnis dengan mode unlimited ditandai is_unlimited_mode = 1
        try {
            DB::statement("
                UPDATE transactions t
                JOIN businesses b ON t.business_id = b.id
                SET t.is_unlimited_mode = 1
                WHERE b.package_type = 'unlimited'
                   OR b.is_unlimited_coins = 1
                   OR (b.coins_per_transaction IS NOT NULL AND b.coins_per_transaction <= 0)
            ");
        } catch (\Throwable $e) {}

        // Rebuild jurnal penjualan untuk transaksi mode unlimited yang sempat tercatat memiliki HPP/Persediaan
        try {
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
        if (Schema::hasColumn('transactions', 'is_unlimited_mode')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('is_unlimited_mode');
            });
        }
    }
};
