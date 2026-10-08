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


