<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('stock:recalculate-moving-averages', function () {
    $this->info('Recalculating Moving Averages for all ingredients across all outlets...');
    $ingredients = \App\Models\Ingredient::all();
    $outlets = \App\Models\Outlet::all();
    $mainOutlet = $outlets->firstWhere('is_main', true) ?: $outlets->first();

    foreach ($ingredients as $ing) {
        $visited = [];
        if ($mainOutlet) {
            $ing->recomputeMovingAverageFromHistory((int)$mainOutlet->id, $visited);
        }
        foreach ($outlets as $out) {
            if (!in_array((int)$out->id, $visited)) {
                $ing->recomputeMovingAverageFromHistory((int)$out->id, $visited);
            }
        }
    }
    $this->info('Completed successfully! All transfer prices and destination moving averages are in sync.');
})->purpose('Recalculate Moving Averages and cascade transfers for all ingredients');

Artisan::command('shifts:sync-initial-cash-journals', function () {
    $this->info('Sinkronisasi jurnal modal kasir (Saldo Awal vs Tarik dari Kas Besar)...');
    $shifts = \App\Models\Shift::where('initial_cash', '>', 0)->orderBy('id', 'asc')->get();
    $count = 0;

    foreach ($shifts as $shift) {
        // Cek shift sebelumnya pada outlet yang sama
        $prev = \App\Models\Shift::where('outlet_id', $shift->outlet_id)
            ->where('status', 'CLOSED')
            ->where(function ($q) use ($shift) {
                if ($shift->opened_at) {
                    $q->where('opened_at', '<', $shift->opened_at)
                      ->orWhere(function ($sub) use ($shift) {
                          $sub->where('opened_at', '=', $shift->opened_at)
                              ->where('id', '<', $shift->id);
                      });
                } else {
                    $q->where('id', '<', $shift->id);
                }
            })
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->first();

        $existing = \App\Models\CashTransaction::where('shift_id', $shift->id)
            ->whereIn('category', ['TARIK_MODAL_KASIR', 'SALDO_AWAL', 'INITIAL_BALANCE'])
            ->first();

        // 1. KASUS SHIFT PERTAMA SEKALI (Tidak ada shift sebelumnya)
        // Saldo awal kasir shift pertama SUDAH dibukukan oleh sistem sebagai Modal Kasir Awal (INITIAL_BALANCE_CASH).
        // Hapus duplikasi CashTransaction jika ada agar saldo awal tidak terhitung ganda (double).
        if (!$prev) {
            if ($existing) {
                \App\Services\AccountingService::deleteReferenceEntry('CASH_TRANSACTION', $existing->id, $shift->business_id ?: 1);
                $existing->delete();
                $this->info("Shift #{$shift->id} ({$shift->shift_name}) [SHIFT PERTAMA]: Duplikasi jurnal Saldo Awal berhasil dihapus (tetap menggunakan jurnal Modal Kasir Awal JE-0002).");
            }
            continue;
        }

        // 2. KASUS SHIFT SELANJUTNYA ($prev ada)
        if ($existing) {
            \App\Services\AccountingService::postCashTransaction($existing);
            continue;
        }

        // Hitung sisa kas laci shift lalu
        $prevRemaining = 0.0;
        if ($prev && $prev->closing_cash !== null) {
            $prevDeposit = $prev->deposit_amount !== null ? (float)$prev->deposit_amount : 0.0;
            $prevRemaining = max(0.0, (float)$prev->closing_cash - $prevDeposit);
        }

        // Jika sisa laci shift lalu 0 atau shift notes mencatat kas besar:
        if ($prevRemaining == 0.0 || str_contains($shift->notes ?? '', 'Kas Besar')) {
            $amount = (float)$shift->initial_cash;
            $approverId = $shift->created_by ?: ($shift->user_id ?: 1);
            $txDate = $shift->opened_at ? substr($shift->opened_at, 0, 10) : now()->toDateString();

            $ct = \App\Models\CashTransaction::create([
                'business_id'    => $shift->business_id ?: 1,
                'outlet_id'      => $shift->outlet_id,
                'shift_id'       => $shift->id,
                'transaction_no' => 'CT-KB-' . date('YmdHis', strtotime($shift->opened_at ?: now())) . '-' . rand(100, 999),
                'name'           => "Ambil Modal Kasir Shift #{$shift->id} ({$shift->shift_name})",
                'category'       => 'TARIK_MODAL_KASIR',
                'type'           => 'OUT',
                'activity_type'  => 'OPERATING',
                'account'        => 'KAS_BESAR',
                'payment_method' => 'CASH',
                'amount'         => $amount,
                'date'           => $txDate,
                'status'         => 'APPROVED',
                'notes'          => "Penarikan modal awal kasir dari Kas Besar untuk {$shift->shift_name}.",
                'user_id'        => $shift->user_id,
                'created_by'     => $approverId,
                'approved_by'    => $approverId,
                'approved_at'    => $shift->opened_at ?: now(),
            ]);

            \App\Services\AccountingService::postCashTransaction($ct);
            $this->info("Shift #{$shift->id} ({$shift->shift_name}): Berhasil posting jurnal TARIK MODAL dari Kas Besar Rp " . number_format($amount, 0, ',', '.'));
            $count++;
        }
    }

    $this->info("Selesai! {$count} jurnal modal kasir berhasil disinkronkan.");
})->purpose('Sinkronkan jurnal modal awal kasir (Saldo Awal vs Tarik dari Kas Besar) untuk shift-shift sebelumnya');

Artisan::command('accounting:sync-all {business_id?}', function (?int $business_id = null) {
    $this->info('Memulai sinkronisasi total Kartu Stok & Jurnal Akuntansi...');
    $res = \App\Services\AccountingService::syncAllHistoricalData($business_id);
    $this->info('Sinkronisasi selesai! Hasil:');
    $this->table(['Entitas', 'Jumlah Diproses'], [
        ['Saldo Awal', $res['initial_balances'] ?? 0],
        ['Transaksi POS', $res['transactions'] ?? 0],
        ['Pelunasan Kasbon', $res['receivable_payments'] ?? 0],
        ['Shift Kasir', $res['shifts'] ?? 0],
        ['Kas Masuk/Keluar', $res['cash_transactions'] ?? 0],
        ['Biaya Operasional', $res['expenses'] ?? 0],
        ['Transfer Stok', $res['transfers'] ?? 0],
        ['Waste / Kerusakan', $res['wastes'] ?? 0],
        ['Opname Fisik', $res['opnames'] ?? 0],
        ['Pembelian Stok', $res['purchases'] ?? 0],
        ['Pelunasan Hutang', $res['payable_payments'] ?? 0],
        ['Total Jurnal Buku Besar', $res['total_journal_entries'] ?? 0],
    ]);
})->purpose('Sinkronisasi ulang seluruh Kartu Stok dan Jurnal Akuntansi secara kronologis');


Artisan::command('stock:fix-adjustment-deficit-price {business_id?}', function (?int $business_id = null) {
    // Fix: ADJUSTMENT_IN yang diinput saat stok defisit seharusnya menyimpan
    // unit_price = avg pada saat defisit (avg terkunci), bukan avg terkini yang
    // mungkin sudah berubah akibat pembelian setelah defisit.
    //
    // Command ini:
    // 1. Mencari semua ADJUSTMENT_IN yang bertipe "Penyesuaian" (bukan auto-normalisasi dari PURCHASE)
    // 2. Menemukan movement keluar (SALE_USAGE) yang mendahului ADJUSTMENT_IN tersebut
    //    dan membawa stok ke defisit
    // 3. Mengunci unit_price ADJUSTMENT_IN ke avg saat defisit
    // 4. Memanggil recomputeMovingAverageFromHistory untuk menghitung ulang seluruh kartu stok

    $this->info('Fixing ADJUSTMENT_IN movements with wrong deficit price...');

    $ingredients = \App\Models\Ingredient::when($business_id, fn($q) => $q->where('business_id', $business_id))->get();
    $outlets     = \App\Models\Outlet::when($business_id, fn($q) => $q->where('business_id', $business_id))->get();
    $fixedCount  = 0;

    foreach ($ingredients as $ing) {
        $konversi = max((float)$ing->konversi, 1);
        foreach ($outlets as $outlet) {
            // Ambil semua movements secara kronologis untuk outlet ini
            $movements = \App\Models\StockMovement::where('ingredient_id', $ing->id)
                ->where('outlet_id', $outlet->id)
                ->where('type', '!=', 'INITIAL')
                ->orderBy('date', 'asc')
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($movements->isEmpty()) continue;

            // Simulasi running stock untuk menemukan momen defisit
            $runningStock = 0.0;
            $lastOutPrice = 0.0; // unit_price dari movement keluar terakhir
            $lastOutCostBefore = 0.0;

            foreach ($movements as $m) {
                $qty = (float)$m->qty;
                $outTypes = ['SALE_USAGE', 'ADJUSTMENT_OUT', 'WASTE', 'TRANSFER_OUT', 'PREP_USAGE'];
                $inTypes  = ['PURCHASE', 'TRANSFER_IN', 'ADJUSTMENT_IN', 'ADJUSTMENT_PLUS', 'PREP_OUTPUT'];

                if (in_array($m->type, $outTypes)) {
                    if ((float)$m->unit_price > 0) {
                        $lastOutPrice = (float)$m->unit_price;
                    }
                    if ((float)$m->cost_before > 0) {
                        $lastOutCostBefore = (float)$m->cost_before;
                    }
                    $runningStock -= $qty;
                } elseif (in_array($m->type, ['ADJUSTMENT_IN', 'ADJUSTMENT_PLUS'])) {
                    // Cek: apakah pada saat movement ini diproses, stok sedang defisit?
                    if ($runningStock < -0.0001 && $lastOutPrice > 0) {
                        // unit_price yang BENAR = avg saat defisit (dari movement keluar terakhir)
                        $correctUnitPrice = $lastOutPrice;
                        $currentUnitPrice = (float)$m->unit_price;

                        // Hanya update jika unit_price berbeda signifikan (> 1% selisih)
                        if (abs($currentUnitPrice - $correctUnitPrice) > ($correctUnitPrice * 0.01)) {
                            $m->unit_price  = round($correctUnitPrice, 4);
                            $m->total_price = round(($qty / $konversi) * $correctUnitPrice, 2);
                            $m->saveQuietly();
                            $fixedCount++;
                            $this->line("  Fixed: {$ing->name} @ outlet {$outlet->name} | ADJUSTMENT_IN #{$m->id} | was {$currentUnitPrice} → corrected to {$correctUnitPrice}");
                        }
                    }
                    $runningStock += $qty;
                } else {
                    $runningStock += $qty;
                }
            }
        }
    }

    $this->info("Fixed {$fixedCount} ADJUSTMENT_IN movements.");
    $this->info('Now recomputing all moving averages...');

    foreach ($ingredients as $ing) {
        $mainOutlet = $outlets->firstWhere('is_main', true) ?: $outlets->first();
        $visited = [];
        if ($mainOutlet) {
            $ing->recomputeMovingAverageFromHistory((int)$mainOutlet->id, $visited);
        }
        foreach ($outlets as $out) {
            if (!in_array((int)$out->id, $visited)) {
                $ing->recomputeMovingAverageFromHistory((int)$out->id, $visited);
            }
        }
    }

    $this->info('All done! Moving averages recomputed with corrected deficit prices.');
})->purpose('Fix ADJUSTMENT_IN movements recorded during stock deficit that used wrong (post-purchase) avg price instead of locked deficit avg, then recompute all moving averages');

Artisan::command('stock:heal-void-urgent-journals {--business_id=}', function () {
    $bizId = $this->option('business_id');
    $this->info('Memulai pemulihan dan penyelarasan data stok, nota urgent, dan jurnal...');
    \App\Services\AccountingService::healCorruptedUrgentNotesAndStocks($bizId ? (int)$bizId : null);
    $stats = \App\Services\AccountingService::syncAllHistoricalData($bizId ? (int)$bizId : null);
    $this->info("Pemulihan selesai! Total jurnal tersinkronisasi: " . ($stats['total_journal_entries'] ?? 0));
})->purpose('Heal corrupted urgent notes, negative stock desyncs, and reconcile all journals');

Artisan::command('data:fix-retroactive-dates {--target-date=2026-10-24} {--old-date=2026-10-23}', function () {
    $targetDate = $this->option('target-date') ?: '2026-10-24';
    $oldDate    = $this->option('old-date') ?: '2026-10-23';

    $this->info("=== MEMULAI PERBAIKAN TANGGAL RETROAKTIF DARI {$oldDate} KE {$targetDate} ===");

    // 1. PERBAIKI TRANSFER STOK (Contoh: TRF-20261023-0001 yang dibuat di tanggal 24 subuh)
    $transfersToFix = \App\Models\Transfer::where(function ($q) use ($oldDate, $targetDate) {
        $q->where('date', $oldDate)->whereDate('created_at', $targetDate);
    })->orWhere(function ($q) use ($oldDate, $targetDate) {
        $q->where('transfer_no', 'like', 'TRF-' . str_replace('-', '', $oldDate) . '-%')
          ->whereDate('created_at', $targetDate);
    })->get();

    $this->info("Menemukan {$transfersToFix->count()} transfer yang perlu diperbaiki.");

    foreach ($transfersToFix as $trf) {
        $oldNo = $trf->transfer_no;
        $bizId = $trf->business_id ?: 1;

        $dateClean = str_replace('-', '', $targetDate);
        $countToday = \App\Models\Transfer::where('business_id', $bizId)
            ->where('date', $targetDate)
            ->count() + 1;
        $newNo = 'TRF-' . $dateClean . '-' . str_pad($countToday, 4, '0', STR_PAD_LEFT);

        while (\App\Models\Transfer::where('business_id', $bizId)->where('transfer_no', $newNo)->exists()) {
            $countToday++;
            $newNo = 'TRF-' . $dateClean . '-' . str_pad($countToday, 4, '0', STR_PAD_LEFT);
        }

        $trf->date = $targetDate;
        $trf->transfer_no = $newNo;
        $trf->saveQuietly();

        \App\Models\StockMovement::where('transfer_id', $trf->id)->update([
            'date' => $targetDate,
        ]);
        \Illuminate\Support\Facades\DB::table('stock_movements')
            ->where('transfer_id', $trf->id)
            ->update([
                'note' => \Illuminate\Support\Facades\DB::raw("REPLACE(note, '{$oldNo}', '{$newNo}')")
            ]);

        $jes = \App\Models\JournalEntry::where('reference_type', 'TRANSFER')
            ->where('reference_id', $trf->id)
            ->get();
        foreach ($jes as $je) {
            $je->entry_date = $targetDate;
            $je->description = str_replace($oldNo, $newNo, $je->description);
            $je->saveQuietly();
        }

        \App\Services\AccountingService::postTransfer($trf);
        $this->info("  [TRANSFER DIPERBAIKI] {$oldNo} -> {$newNo} (Tanggal: {$targetDate})");
    }

    // 2. PERBAIKI TRANSAKSI PENJUALAN POS (Contoh: TRX-20261023-0006 yang dibuat di tanggal 24 subuh)
    $ordersToFix = \App\Models\Transaction::where(function ($q) use ($oldDate, $targetDate) {
        $q->where('date', $oldDate)->whereDate('created_at', $targetDate);
    })->orWhere(function ($q) use ($oldDate, $targetDate) {
        $q->where('order_number', 'like', 'TRX-' . str_replace('-', '', $oldDate) . '-%')
          ->whereDate('created_at', $targetDate);
    })->pluck('order_number')->unique()->filter();

    $this->info("Menemukan {$ordersToFix->count()} nomor nota order POS yang perlu diperbaiki.");

    foreach ($ordersToFix as $oldOrderNo) {
        $trxItems = \App\Models\Transaction::where('order_number', $oldOrderNo)->get();
        if ($trxItems->isEmpty()) continue;

        $first = $trxItems->first();
        $bizId = $first->business_id ?: 1;

        $newOrderNo = \App\Services\OrderNumberService::generateNextOrderNumber($bizId, $targetDate);

        foreach ($trxItems as $trx) {
            $trx->date = $targetDate;
            $trx->order_number = $newOrderNo;
            $trx->saveQuietly();

            \App\Models\StockMovement::where('transaction_id', $trx->id)->update([
                'date' => $targetDate,
            ]);
            \Illuminate\Support\Facades\DB::table('stock_movements')
                ->where('transaction_id', $trx->id)
                ->update([
                    'note' => \Illuminate\Support\Facades\DB::raw("REPLACE(note, '{$oldOrderNo}', '{$newOrderNo}')")
                ]);

            $jes = \App\Models\JournalEntry::where('reference_type', 'TRANSACTION')
                ->where('reference_id', $trx->id)
                ->get();
            foreach ($jes as $je) {
                $je->entry_date = $targetDate;
                $je->description = str_replace($oldOrderNo, $newOrderNo, $je->description);
                $je->saveQuietly();
            }

            \App\Services\AccountingService::recordPosTransaction($trx);
        }

        \App\Models\UrgentNote::where('order_number', $oldOrderNo)->update([
            'order_number' => $newOrderNo,
        ]);

        $rec = \App\Models\Receivable::where('order_number', $oldOrderNo)->first();
        if ($rec) {
            $rec->order_number = $newOrderNo;
            $rec->issue_date = $targetDate;
            $rec->due_date = $targetDate;
            $rec->saveQuietly();
        }

        $this->info("  [POS ORDER DIPERBAIKI] {$oldOrderNo} -> {$newOrderNo} (Tanggal: {$targetDate})");
    }

    $this->info("Semua data berhasil diperbarui ke tanggal {$targetDate}!");
})->purpose('Perbaiki tanggal transaksi dan transfer stok yang tercatat pada tanggal sebelumnya akibat shift malam');



