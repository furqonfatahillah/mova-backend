<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use App\Services\AccountingService;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            // Jalankan perbaikan otomatis pada seluruh data berjalan:
            // 1. Selaraskan current_stock di outlet_ingredients dengan akumulasi riil mutasi
            // 2. Batalkan UrgentNote milik transaksi yang berstatus CANCELLED / VOID
            // 3. Selaraskan deducted_qty dan pending_qty pada UrgentNote sesuai stok riil saat transaksi dibuat
            // 4. Sinkronisasi ulang jurnal umum agar HPP Aktual, HPP Teoritis, dan Waste seimbang sempurna
            AccountingService::healCorruptedUrgentNotesAndStocks();
            AccountingService::syncAllHistoricalData();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Heal corrupted urgent notes and stock migration: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
