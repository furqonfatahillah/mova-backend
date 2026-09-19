<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=========================================================\n";
echo "       MOVA POS — RESET & CLEAR TRANSACTION DATA        \n";
echo "=========================================================\n\n";

$tablesToClear = [
    'receivable_payments' => 'Riwayat Pembayaran Piutang',
    'receivables'         => 'Buku Piutang Usaha',
    'cash_transactions'   => 'Mutasi Kas Nyata (Cash Flow)',
    'operating_expenses'  => 'Biaya Operasional (OPEX)',
    'urgent_notes'        => 'Nota Urgent Bahan Habis',
    'waste_logs'          => 'Catatan Bahan Terbuang (Waste)',
    'batch_preps'         => 'Produksi Batch Olahan',
    'transfer_items'      => 'Item Transfer Bahan',
    'transfers'           => 'Sesi Transfer Stok',
    'opnames'             => 'Stock Opname Fisik & Anomali',
    'stock_movements'     => 'Kartu Stok & Mutasi',
    'transactions'        => 'Transaksi Penjualan POS Kasir',
    'shifts'              => 'Sesi Shift Kasir',
];

try {
    DB::statement('SET FOREIGN_KEY_CHECKS=0;');

    $totalCleared = 0;
    foreach ($tablesToClear as $table => $label) {
        if (Schema::hasTable($table)) {
            $count = DB::table($table)->count();
            DB::table($table)->truncate();
            echo " [✓] " . str_pad($label . " ({$table})", 45) . " : {$count} data dihapus\n";
            $totalCleared += $count;
        }
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=1;');

    echo "\n=========================================================\n";
    echo " STATUS: SUKSES! Total {$totalCleared} data transaksi dibersihkan.\n";
    echo " Master data (User, Menu, Resep, Bahan, Outlet) tetap AMAN.\n";
    echo " Aplikasi sekarang SIAP 100% untuk proses testing baru.\n";
    echo "=========================================================\n";

} catch (\Exception $e) {
    DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    echo "\n[ERROR]: " . $e->getMessage() . "\n";
}
