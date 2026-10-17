<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BatchPrep;
use App\Models\Business;
use App\Models\CashTransaction;
use App\Models\Ingredient;
use App\Models\IngredientInitialBalance;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Menu;
use App\Models\OperatingExpense;
use App\Models\Opname;
use App\Models\Outlet;
use App\Models\OutletIngredient;
use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\UrgentNote;
use App\Models\WasteLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountingService
{
    /**
     * Cache existing journal entry numbers by reference key (reference_type:reference_id)
     * to preserve stable journal numbers across syncAllHistoricalData executions.
     */
    protected static array $preservedEntryNos = [];

    /**
     * Map payment method string to Account Code
     */
    public static function getAccountCodeForPaymentMethod(?string $method): string
    {
        $m = strtoupper(trim((string)$method));
        if ($m === 'KAS_BESAR' || $m === 'KAS_UTAMA' || $m === 'BRANKAS') {
            return '1-11001'; // KAS BESAR
        }
        if ($m === 'CASH' || $m === 'TUNAI' || $m === 'PETTY_CASH' || $m === 'KAS' || $m === 'KASIR' || $m === 'KAS_KECIL') {
            return '1-11002'; // KAS KECIL KASIR
        }
        if (
            str_contains($m, 'QRIS') ||
            str_contains($m, 'GRAB') ||
            str_contains($m, 'SHOPEE') ||
            str_contains($m, 'GOFOOD') ||
            str_contains($m, 'GOJEK') ||
            str_contains($m, 'ECOMMERCE') ||
            str_contains($m, 'E-COMMERCE') ||
            str_contains($m, 'ONLINE') ||
            str_contains($m, 'DELIVERY') ||
            str_contains($m, 'TIKTOK') ||
            str_contains($m, 'TOKOPEDIA') ||
            str_contains($m, 'APLIKASI')
        ) {
            return '1-11004'; // KAS APLIKASI (QRIS & E-COMMERCE)
        }
        if (str_contains($m, 'TRANSFER') || str_contains($m, 'BANK') || str_contains($m, 'DEBIT') || str_contains($m, 'EDC') || str_contains($m, 'KARTU') || str_contains($m, 'BCA') || str_contains($m, 'MANDIRI') || str_contains($m, 'BRI') || str_contains($m, 'BNI')) {
            return '1-11003'; // BANK
        }
        if ($m === 'HUTANG' || $m === 'TEMPO' || $m === 'CREDIT' || $m === 'SUPPLIER') {
            return '2-21001'; // HUTANG USAHA
        }
        if ($m === 'KASBON' || $m === 'PIUTANG' || str_contains($m, 'KASBON') || str_contains($m, 'HUTANG_PELANGGAN') || str_contains($m, 'KARYAWAN') || str_contains($m, 'STAFF') || str_contains($m, 'PEGAWAI')) {
            return '1-12001'; // PIUTANG USAHA
        }
        return '1-11002'; // Default Kas Kecil Kasir
    }

    /**
     * Check whether an ingredient is Packaging / Perlengkapan
     */
    public static function isPerlengkapan($ing): bool
    {
        if (!$ing) return false;
        $code = strtoupper((string)($ing->code ?? ''));
        $cat = strtolower((string)($ing->category ?? ''));
        $name = strtolower((string)($ing->name ?? ''));

        return str_starts_with($code, 'PLK-') ||
               str_starts_with($code, 'PKG-') ||
               str_contains($cat, 'perlengkapan') ||
               str_contains($cat, 'packaging') ||
               str_contains($cat, 'kemasan') ||
               str_contains($cat, 'cup') ||
               str_contains($cat, 'sedotan') ||
               str_contains($cat, 'pipet') ||
               str_contains($cat, 'tisu') ||
               str_contains($cat, 'tissue') ||
               str_contains($name, 'cup') ||
               str_contains($name, 'pipet') ||
               str_contains($name, 'sedotan') ||
               str_contains($name, 'tissue') ||
               str_contains($name, 'tisu') ||
               str_contains($name, 'sealer') ||
               str_contains($name, 'kantong') ||
               str_contains($name, 'plastik') ||
               str_contains($name, 'kresek') ||
               str_contains($name, 'box');
    }

    /**
     * Record a balanced journal entry with debit and credit lines.
     */
    public static function recordEntry(array $data, array $lines, ?int $businessId = null): ?JournalEntry
    {
        $bizId = $businessId ?: ($data['business_id'] ?? 1);
        Account::seedForBusiness($bizId);

        $defaultOutletId = $data['outlet_id'] ?? Outlet::where('business_id', $bizId)->orderBy('id', 'asc')->value('id');

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $cleanLines = [];

        foreach ($lines as $l) {
            $deb = round((float)($l['debit'] ?? 0), 2);
            $crd = round((float)($l['credit'] ?? 0), 2);
            if ($deb <= 0 && $crd <= 0) continue;

            $totalDebit += $deb;
            $totalCredit += $crd;

            $targetOutletId = $l['outlet_id'] ?? $defaultOutletId;
            $rawCode = $l['account_code'];

            // If raw code has no sub-account dot suffix, outlet is provided, and it's not equity (3-), resolve sub-account code
            $finalCode = $rawCode;
            if (!str_contains($rawCode, '.') && $targetOutletId && !str_starts_with($rawCode, '3-')) {
                $finalCode = Account::getSubAccountCode($rawCode, $bizId, $targetOutletId);
            }

            $cleanLines[] = [
                'account_code' => $finalCode,
                'outlet_id'    => $targetOutletId,
                'debit'        => $deb,
                'credit'       => $crd,
                'memo'         => $l['memo'] ?? ($data['description'] ?? null),
            ];
        }

        if (empty($cleanLines)) {
            return null;
        }

        $totalDebit = round($totalDebit, 2);
        $totalCredit = round($totalCredit, 2);
        $diff = round($totalDebit - $totalCredit, 2);

        // Auto-balance minor cent rounding differences if <= Rp 0.10
        if (abs($diff) > 0 && abs($diff) <= 0.10 && count($cleanLines) > 0) {
            if ($diff > 0) {
                $cleanLines[count($cleanLines) - 1]['credit'] += $diff;
                $totalCredit += $diff;
            } else {
                $cleanLines[count($cleanLines) - 1]['debit'] += abs($diff);
                $totalDebit += abs($diff);
            }
        }

        $isBalanced = abs($totalDebit - $totalCredit) < 0.01;

        // Delete previous entry with the same reference if updating, while preserving original entry_no
        $refKey = (!empty($data['reference_type']) && !empty($data['reference_id']))
            ? ($data['reference_type'] . ':' . $data['reference_id'])
            : null;

        $existingEntryNo = null;
        if ($refKey) {
            $existing = JournalEntry::where('business_id', $bizId)
                ->where('reference_type', $data['reference_type'])
                ->where('reference_id', $data['reference_id'])
                ->first();
            if ($existing) {
                $existingEntryNo = $existing->entry_no;
                $existing->delete();
            } elseif (isset(self::$preservedEntryNos[$refKey])) {
                $existingEntryNo = self::$preservedEntryNos[$refKey];
            }
        }

        $entryDate = $data['entry_date'] ?? now()->toDateString();
        $entryNo = $data['entry_no'] ?? ($existingEntryNo ?: JournalEntry::generateEntryNo($bizId, $entryDate));

        $accountsMap = Account::where('business_id', $bizId)->get()->keyBy('code');

        return DB::transaction(function () use ($bizId, $data, $defaultOutletId, $cleanLines, $entryDate, $entryNo, $totalDebit, $totalCredit, $isBalanced, $accountsMap) {
            $entry = JournalEntry::create([
                'business_id'    => $bizId,
                'outlet_id'      => $data['outlet_id'] ?? $defaultOutletId,
                'entry_no'       => $entryNo,
                'entry_date'     => $entryDate,
                'entry_type'     => $data['entry_type'] ?? 'GENERAL',
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id'   => $data['reference_id'] ?? null,
                'description'    => $data['description'] ?? null,
                'total_debit'    => $totalDebit,
                'total_credit'   => $totalCredit,
                'is_balanced'    => $isBalanced,
                'created_by'     => $data['created_by'] ?? null,
            ]);

            foreach ($cleanLines as $l) {
                $acc = $accountsMap->get($l['account_code']);
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id'       => $acc?->id,
                    'account_code'     => $l['account_code'],
                    'outlet_id'        => $l['outlet_id'] ?? $defaultOutletId,
                    'entry_date'       => $entryDate,
                    'debit'            => $l['debit'],
                    'credit'           => $l['credit'],
                    'memo'             => $l['memo'] ?? null,
                ]);
            }

            return $entry;
        });
    }

    /**
     * 1. POST INITIAL BALANCE (Modal Awal Kasir Perdana & Saldo Awal Persediaan)
     */
    public static function postInitialBalance(Business $business): void
    {
        $bizId = $business->id;
        $outlets = Outlet::where('business_id', $bizId)->where('active', true)->get();

        foreach ($outlets as $outlet) {
            // A. Initial Cash Drawer
            $firstShift = Shift::where('outlet_id', $outlet->id)->orderBy('opened_at', 'asc')->first();
            $initialCash = $firstShift ? (float)$firstShift->initial_cash : 0.0;
            $cashInitDate = $firstShift ? substr($firstShift->opened_at, 0, 10) : date('Y-m-01');

            if ($initialCash > 0) {
                self::recordEntry([
                    'business_id'    => $bizId,
                    'outlet_id'      => $outlet->id,
                    'entry_date'     => $cashInitDate,
                    'entry_type'     => 'INITIAL_BALANCE',
                    'reference_type' => 'INITIAL_BALANCE_CASH',
                    'reference_id'   => $outlet->id,
                    'description'    => "Modal Kas Awal Kasir Perdana - {$outlet->name}",
                ], [
                    ['account_code' => '1-11002', 'debit' => $initialCash, 'credit' => 0, 'memo' => 'Modal Kasir Awal'],
                    ['account_code' => '3-31001', 'debit' => 0, 'credit' => $initialCash, 'memo' => 'Modal Pemilik Disetor'],
                ], $bizId);
            }

            // B. Initial Inventory Balance
            $ings = Ingredient::where('business_id', $bizId)->with('outletIngredients')->get();
            $totalBahanAwal = 0.0;
            $totalPlkAwal = 0.0;
            $stockInitDate = \App\Models\IngredientInitialBalance::where('outlet_id', $outlet->id)
                ->whereNotNull('tanggal_saldo_awal')
                ->value('tanggal_saldo_awal');

            foreach ($ings as $ing) {
                $isPlk = self::isPerlengkapan($ing);

                $initRow = \App\Models\IngredientInitialBalance::where('outlet_id', $outlet->id)->where('ingredient_id', $ing->id)->first();
                $stokAwal = $initRow ? (float)$initRow->stok_awal : 0.0;
                $costUnit = $ing->costPerPakaiForOutlet($outlet->id);
                $saldoAwalNom = $initRow ? (float)$initRow->saldo_awal_nominal : round($stokAwal * $costUnit, 2);

                if ($saldoAwalNom > 0) {
                    // Cek apakah saldo awal ini berasal dari auto-adopt nilai plus saat saldo awal 0
                    $adoptedMov = StockMovement::where('ingredient_id', $ing->id)
                        ->where('outlet_id', $outlet->id)
                        ->where('type', 'INITIAL')
                        ->where('note', 'like', '%[Saldo Awal Otomatis]%')
                        ->first();

                    if ($adoptedMov) {
                        // Jurnal untuk nilai plus yang menjadi saldo awal ketika saldo awal 0 adalah Persediaan pada Persediaan
                        self::postAdoptedInitialBalance($adoptedMov, $saldoAwalNom, $outlet->id);
                        continue; // JANGAN masukkan ke Modal Saldo Awal Persediaan (3-31001)
                    }

                    if ($isPlk) {
                        $totalPlkAwal += $saldoAwalNom;
                    } else {
                        $totalBahanAwal += $saldoAwalNom;
                    }
                    if (!$stockInitDate) {
                        $stockInitDate = $initRow?->tanggal_saldo_awal ?: null;
                    }
                }
            }

            // Pastikan mutasi masuk pertama bertipe INITIAL dengan [Saldo Awal Otomatis] lainnya tercatat jurnal Persediaan pada Persediaan
            $allAdoptedMovements = StockMovement::where('outlet_id', $outlet->id)
                ->where('type', 'INITIAL')
                ->where('note', 'like', '%[Saldo Awal Otomatis]%')
                ->get();
            foreach ($allAdoptedMovements as $am) {
                self::postAdoptedInitialBalance($am, null, $outlet->id);
            }

            $totalPersediaanAwal = round($totalBahanAwal + $totalPlkAwal, 2);
            if ($totalPersediaanAwal > 0) {
                $lines = [];
                if ($totalBahanAwal > 0) {
                    $lines[] = ['account_code' => '1-13001', 'debit' => $totalBahanAwal, 'credit' => 0, 'memo' => 'Saldo Awal Bahan Baku'];
                }
                if ($totalPlkAwal > 0) {
                    $lines[] = ['account_code' => '1-13002', 'debit' => $totalPlkAwal, 'credit' => 0, 'memo' => 'Saldo Awal Perlengkapan'];
                }
                $lines[] = ['account_code' => '3-31001', 'debit' => 0, 'credit' => $totalPersediaanAwal, 'memo' => 'Modal Saldo Awal Persediaan'];

                $invEntryDate = $stockInitDate ?: ($firstShift ? substr($firstShift->opened_at, 0, 10) : date('Y-m-01'));

                self::recordEntry([
                    'business_id'    => $bizId,
                    'outlet_id'      => $outlet->id,
                    'entry_date'     => $invEntryDate,
                    'entry_type'     => 'INITIAL_BALANCE',
                    'reference_type' => 'INITIAL_BALANCE_STOCK',
                    'reference_id'   => $outlet->id,
                    'description'    => "Saldo Awal Persediaan Fisik - {$outlet->name}",
                ], $lines, $bizId);
            }
        }
    }

    /**
     * 1B. POST ADOPTED INITIAL BALANCE (Saldo Awal Otomatis dari Mutasi Masuk Pertama ketika Saldo Awal = 0)
     * Jurnal: Persediaan pada Persediaan (Debit: 1-13001/1-13002, Kredit: 1-13001/1-13002)
     */
    public static function postAdoptedInitialBalance(StockMovement $sm, ?float $amount = null, ?int $outletId = null): ?JournalEntry
    {
        $bizId = $sm->business_id ?? 1;
        $outletId = $outletId ?: ($sm->outlet_id ?: 1);
        $ing = $sm->ingredient ?: Ingredient::find($sm->ingredient_id);
        $konversi = $ing ? max((float)$ing->konversi, 1) : 1;

        if ($amount === null || $amount <= 0) {
            $amount = (float)$sm->total_price > 0
                ? (float)$sm->total_price
                : round((float)$sm->qty * (float)($sm->unit_price > 0 ? ($sm->unit_price > 1000 && $konversi > 1 ? $sm->unit_price / $konversi : $sm->unit_price) : ($ing ? $ing->costPerPakaiForOutlet($outletId) : 0)), 2);
        }

        if ($amount <= 0) {
            return null;
        }

        $date = $sm->date ?: now()->toDateString();
        $isPlk = self::isPerlengkapan($ing);
        $invAcc = $isPlk ? '1-13002' : '1-13001';
        $ingName = $ing ? $ing->name : 'Bahan';

        $lines = [
            [
                'account_code' => $invAcc,
                'outlet_id'    => $outletId,
                'debit'        => $amount,
                'credit'       => 0,
                'memo'         => "Saldo Awal Persediaan {$ingName} (Saldo Awal Otomatis)",
            ],
            [
                'account_code' => $invAcc,
                'outlet_id'    => $outletId,
                'debit'        => 0,
                'credit'       => $amount,
                'memo'         => "Alokasi Persediaan Masuk {$ingName} (Persediaan pada Persediaan)",
            ],
        ];

        return self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $date,
            'entry_type'     => 'INITIAL_BALANCE',
            'reference_type' => 'STOCK_MOVEMENT',
            'reference_id'   => $sm->id,
            'description'    => "Saldo Awal Otomatis (Persediaan pada Persediaan) #{$sm->id} - {$ingName}",
        ], $lines, $bizId);
    }

    /**
     * 1C. POST BATCH PREP (Masak Olahan: Persediaan Olahan pada Persediaan Bahan Baku)
     */
    public static function postBatchPrep(BatchPrep $bp): ?JournalEntry
    {
        $bizId = $bp->business_id ?? 1;
        $outletId = $bp->outlet_id;
        $totalCost = (float)$bp->total_cost;
        if ($totalCost <= 0) {
            return null;
        }

        // Cek apakah output batch prep ini sudah dijadikan Saldo Awal Otomatis (INITIAL_BALANCE)
        $outputMov = StockMovement::where('batch_prep_id', $bp->id)
            ->where('ingredient_id', $bp->ingredient_id)
            ->first();

        if ($outputMov && $outputMov->type === 'INITIAL') {
            // Sudah tercatat lewat postAdoptedInitialBalance sebagai Persediaan pada Persediaan
            return self::postAdoptedInitialBalance($outputMov, $totalCost, $outletId);
        }

        $date = $bp->date ?: now()->toDateString();
        $semiIng = $bp->ingredient ?: Ingredient::find($bp->ingredient_id);
        $semiName = $semiIng ? $semiIng->name : 'Bahan Olahan';
        $isPlk = self::isPerlengkapan($semiIng);
        $outputAcc = $isPlk ? '1-13002' : '1-13001';

        $lines = [
            [
                'account_code' => $outputAcc,
                'outlet_id'    => $outletId,
                'debit'        => $totalCost,
                'credit'       => 0,
                'memo'         => "Hasil Masak {$bp->batch_no} - {$semiName} ({$bp->actual_output_qty} {$bp->output_unit})",
            ],
            [
                'account_code' => '1-13001',
                'outlet_id'    => $outletId,
                'debit'        => 0,
                'credit'       => $totalCost,
                'memo'         => "Pemakaian Bahan Mentah Masak {$bp->batch_no} - {$semiName}",
            ],
        ];

        return self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $date,
            'entry_type'     => 'BATCH_PREP',
            'reference_type' => 'BATCH_PREP',
            'reference_id'   => $bp->id,
            'description'    => "Produksi Masak Olahan {$bp->batch_no} - {$semiName}",
        ], $lines, $bizId);
    }

    /**
     * 2. POST POS TRANSACTION (Penjualan, Piutang Kasbon, Diskon, & HPP Resep)
     */
    public static function postTransaction(Transaction $t): void
    {
        if ($t->status === 'CANCELLED' || $t->status === 'VOID') {
            self::postCancelledTransaction($t);
            return;
        }

        // Transaksi berstatus HOLD (Open Bill / Meja Makan / Belum Bayar) dicatat sebagai Piutang Open Bill
        // karena bahan baku sudah keluar dari dapur saat pesanan dibuat.
        if (!in_array($t->status, ['PAID', 'HOLD', 'SPLIT_CLOSED'])) {
            self::deleteReferenceEntry('TRANSACTION', $t->id, $t->business_id ?? 1);
            self::deleteReferenceEntry('OPEN_BILL_SETTLEMENT', $t->id, $t->business_id ?? 1);
            return;
        }

        // 1. Post / Update Jurnal Asli Penjualan (Sales Journal)
        // Jika Open Bill, sisi debet selalu mengunci ke Piutang Usaha (1-12001)
        self::buildAndRecordSalesJournal($t);

        // 2. Jika transaksi ini merupakan Open Bill dan statusnya sudah lunas (PAID),
        // bentuk JURNAL BARU untuk Pelunasan (Settlement) yang membalik / melunasi Piutang Usaha
        if ($t->isOpenBill() && $t->status === 'PAID') {
            self::postOpenBillSettlement($t);
        } else {
            self::deleteReferenceEntry('OPEN_BILL_SETTLEMENT', $t->id, $t->business_id ?? 1);
        }
    }

    /**
     * Handle Jurnal untuk Transaksi yang Dibatalkan / Void (CANCELLED)
     * Tidak menghapus jurnal asli transaksi/nota urgent, melainkan memastikan
     * jurnal asli tetap tercatat dan membentuk JURNAL PEMBALIK (REVERSAL) BARU
     * untuk mematikan/meng-offset jurnal asli tersebut secara transparan di buku besar.
     */
    public static function postCancelledTransaction(Transaction $t): void
    {
        $bizId = $t->business_id ?? 1;
        $outletId = $t->outlet_id;

        // Bersihkan jurnal pelunasan open bill (jika sebelumnya sempat lunas kemudian divoid/batal)
        self::deleteReferenceEntry('OPEN_BILL_SETTLEMENT', $t->id, $bizId);

        // 1. Cek apakah Jurnal Asli Penjualan sudah ada
        $origEntry = JournalEntry::with('lines')
            ->where('business_id', $bizId)
            ->where('reference_type', 'TRANSACTION')
            ->where('reference_id', $t->id)
            ->first();

        // Cek apakah transaksi ini merupakan Open Bill
        $isOpenBillTrx = $t->isOpenBill();
        $isUnpaidOpenBill = ($t->status === 'HOLD')
            || ($t->status === 'CANCELLED' && (float)$t->amount_paid <= 0)
            || ($t->status === 'CANCELLED' && str_contains(strtolower($t->notes ?? ''), 'open bill'))
            || ($t->status === 'CANCELLED' && empty($t->payment_method))
            || ($t->status === 'CANCELLED' && Transaction::where('order_number', $t->order_number)->where('status', 'HOLD')->exists());

        // Jika jurnal asli belum ada, bangun jurnal aslinya terlebih dahulu agar memiliki basis pembalik yang akurat
        if (!$origEntry) {
            $origEntry = self::buildAndRecordSalesJournal($t);
        }

        if (!$origEntry) {
            return;
        }

        // Jika transaksi ini merupakan Open Bill, pastikan akun penerimaan di jurnal asli adalah 1-12001 (Piutang Open Bill)
        // dan BUKAN 1-11002 (Kas Kecil Kasir), agar Kas Kecil sama sekali tidak tersentuh
        if ($isOpenBillTrx || $isUnpaidOpenBill) {
            $fixed = false;
            foreach ($origEntry->lines as $ol) {
                $rawCode = explode('.', $ol->account_code)[0];
                if (in_array($rawCode, ['1-11001', '1-11002', '1-11003', '1-11004'])) {
                    $ol->update([
                        'account_code' => '1-12001',
                        'memo'         => "Piutang Open Bill {$t->order_number}",
                    ]);
                    $fixed = true;
                }
            }
            if ($fixed || str_contains($origEntry->description, '(CASH)')) {
                $origEntry->update([
                    'description' => "Penjualan POS Open Bill #{$t->order_number} (Piutang Meja)",
                ]);
                $origEntry->load('lines');
            }
        }

        $isWasted = (strtoupper((string)$t->void_type) === 'WASTED');

        // 2. Bentuk baris jurnal pembalik / void
        // Jika WASTED: Kredit Persediaan di jurnal asli dialihkan menjadi DEBET BIAYA WASTED (5-51003 / 5-51006)
        // Jika WRONG_INPUT: Persediaan bertambah kembali ke gudang (stok aman)
        $reversalLines = [];
        foreach ($origEntry->lines as $l) {
            $deb = (float)$l->debit;
            $crd = (float)$l->credit;
            if ($deb <= 0 && $crd <= 0) continue;

            $rawCode = explode('.', $l->account_code)[0];

            if ($isWasted) {
                // Sisi 1: Kredit Persediaan Bahan di jurnal asli dialihkan menjadi DEBET BIAYA WASTED BAHAN
                if ($crd > 0 && ($rawCode === '1-13001' || str_starts_with($rawCode, '1-13001'))) {
                    $reversalLines[] = [
                        'account_code' => '5-51003',
                        'outlet_id'    => $l->outlet_id ?: $outletId,
                        'debit'        => $crd,
                        'credit'       => 0,
                        'memo'         => "Biaya Kerugian Waste Bahan (Void Wasted Nota #{$t->order_number})",
                    ];
                    continue;
                }
                // Sisi 2: Kredit Persediaan Perlengkapan di jurnal asli dialihkan menjadi DEBET BIAYA WASTED KEMASAN
                if ($crd > 0 && ($rawCode === '1-13002' || str_starts_with($rawCode, '1-13002'))) {
                    $reversalLines[] = [
                        'account_code' => '5-51006',
                        'outlet_id'    => $l->outlet_id ?: $outletId,
                        'debit'        => $crd,
                        'credit'       => 0,
                        'memo'         => "Biaya Kerugian Waste Kemasan (Void Wasted Nota #{$t->order_number})",
                    ];
                    continue;
                }

                // Sisi 3: Akun-akun lainnya dibalik:
                // - Kredit Pendapatan (4-41001) -> Debet (mematikan pendapatan)
                // - Debet Kas/Piutang (1-1100x / 1-12001) -> Kredit (mematikan piutang/kas)
                // - Debet HPP (5-51001, 5-51002, 5-51007, 5-51008) -> Kredit (mematikan HPP penjualan)
                $memoPrefix = "Pembalikan Void Wasted: ";
                if ($deb > 0 && in_array($rawCode, ['5-51001', '5-51002', '5-51007', '5-51008'])) {
                    $memoPrefix = "Pengalihan HPP ke Biaya Waste: ";
                }
                $reversalLines[] = [
                    'account_code' => $rawCode,
                    'outlet_id'    => $l->outlet_id ?: $outletId,
                    'debit'        => $crd,
                    'credit'       => $deb,
                    'memo'         => $memoPrefix . ($l->memo ?: "Nota #{$t->order_number}"),
                ];
            } else {
                // Jika SALAH INPUT: debet dan kredit dibalik murni sehingga stok persediaan bertambah kembali
                $reversalLines[] = [
                    'account_code' => $rawCode,
                    'outlet_id'    => $l->outlet_id ?: $outletId,
                    'debit'        => $crd,
                    'credit'       => $deb,
                    'memo'         => "Pembalikan Void (Salah Input): " . ($l->memo ?: "Nota #{$t->order_number}"),
                ];
            }
        }

        if (empty($reversalLines)) {
            return;
        }

        $cancelDate = $t->cancelled_at ? substr($t->cancelled_at, 0, 10) : ($t->date ?? now()->toDateString());
        $voidTypeLabel = $isWasted ? 'WASTED' : ($t->void_type ?: 'VOID');
        $reason = $t->cancellation_reason ?: ($isWasted ? 'Makanan Terbuang / Rusak' : 'Pembatalan Transaksi');

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $cancelDate,
            'entry_type'     => $isWasted ? 'WASTE' : 'VOID',
            'reference_type' => 'VOID_TRANSACTION',
            'reference_id'   => $t->id,
            'description'    => $isWasted
                ? "Jurnal Void Wasted (Biaya Waste & Pembatalan Penjualan) Nota #{$t->order_number} - {$reason}"
                : "Jurnal Pembalik / Void Nota #{$t->order_number} [{$voidTypeLabel}] - {$reason}",
        ], $reversalLines, $bizId);
    }

    /**
     * Membangun dan mencatat Jurnal Penjualan Asli (Sales Journal)
     */
    public static function buildAndRecordSalesJournal(Transaction $t): ?JournalEntry
    {

        $bizId = $t->business_id ?? 1;
        $outletId = $t->outlet_id;
        $totalNet = (float)$t->total_price;
        $discount = (float)($t->discount_amount ?? 0);
        $grossSales = $totalNet + $discount;
        $pm = strtoupper(trim((string)$t->payment_method));

        $lines = [];

        // A. Revenue Side (4-41001 PENDAPATAN PENJUALAN PRODUK)
        $lines[] = [
            'account_code' => '4-41001',
            'outlet_id'    => $outletId,
            'debit'        => 0,
            'credit'       => $grossSales,
            'memo'         => "Penjualan {$t->order_number}" . ($t->status === 'HOLD' ? ' (Open Bill)' : ''),
        ];

        // Discount if any (4-41003 POTONGAN PENJUALAN DISKON)
        if ($discount > 0) {
            $lines[] = [
                'account_code' => '4-41003',
                'outlet_id'    => $outletId,
                'debit'        => $discount,
                'credit'       => 0,
                'memo'         => "Diskon Penjualan {$t->order_number}",
            ];
        }

        // Debit side (Payment / Receivable)
        // Jika Open Bill (baik yang masih HOLD maupun yang sudah dilunasi kemudian hari),
        // atau transaksi CANCELLED yang belum pernah dibayar (amount_paid <= 0 atau catatan Open Bill),
        // atau metode bayar KASBON/PIUTANG, maka jurnal penjualan asli selalu masuk ke Piutang Usaha (1-12001).
        // Pelunasan uang kas/bank nantinya dicatat di Jurnal Pelunasan tersendiri (OPEN_BILL_SETTLEMENT)!
        $isOpenBillTrx = $t->isOpenBill();
        $isUnpaidOpenBill = ($t->status === 'HOLD')
            || ($t->status === 'CANCELLED' && (float)$t->amount_paid <= 0)
            || ($t->status === 'CANCELLED' && str_contains(strtolower($t->notes ?? ''), 'open bill'))
            || ($t->status === 'CANCELLED' && empty($t->payment_method))
            || ($t->status === 'CANCELLED' && Transaction::where('order_number', $t->order_number)->where('status', 'HOLD')->exists());

        if ($isOpenBillTrx || $isUnpaidOpenBill || $pm === 'KASBON' || $pm === 'PIUTANG') {
            $lines[] = [
                'account_code' => '1-12001', // PIUTANG USAHA
                'outlet_id'    => $outletId,
                'debit'        => $totalNet,
                'credit'       => 0,
                'memo'         => ($isOpenBillTrx || $isUnpaidOpenBill)
                    ? "Piutang Open Bill {$t->order_number}" 
                    : "Piutang Kasbon {$t->order_number}",
            ];
        } else {
            $accCode = self::getAccountCodeForPaymentMethod($pm);
            $lines[] = [
                'account_code' => $accCode,
                'outlet_id'    => $outletId,
                'debit'        => $totalNet,
                'credit'       => 0,
                'memo'         => "Penerimaan {$pm} {$t->order_number}",
            ];
        }

        // B. COGS & Inventory Usage Side (HPP & Pengurangan Persediaan)
        // Prioritize actual StockMovement records created for this transaction to guarantee 100% sync with Kartu Stok!
        // Ignore resolution movements with 'Pelunasan Nota Urgent' to avoid double-counting
        $txMovements = StockMovement::with('ingredient')
            ->where('transaction_id', $t->id)
            ->whereIn('type', ['SALE_USAGE', 'WASTE'])
            ->where('note', 'not like', '%Pelunasan Nota Urgent%')
            ->get();

        $urgentNotes = $t->relationLoaded('urgentNotes')
            ? $t->urgentNotes
            : $t->urgentNotes()->get();

        $cogsBahan = 0.0;
        $cogsPlk = 0.0;
        $cogsTeoritisBahan = 0.0;
        $cogsTeoritisPlk = 0.0;

        if ($txMovements->isNotEmpty()) {
            foreach ($txMovements as $sm) {
                $smIng = $sm->ingredient;
                $isPlk = self::isPerlengkapan($smIng);
                $totalQty = (float)$sm->qty;

                $unitCost = 0.0;
                if ((float)$sm->total_price > 0 && $totalQty > 0) {
                    $unitCost = (float)$sm->total_price / $totalQty;
                } elseif ((float)$sm->cost_after > 0) {
                    $unitCost = (float)$sm->cost_after;
                } elseif ((float)$sm->cost_before > 0) {
                    $unitCost = (float)$sm->cost_before;
                } elseif ($smIng) {
                    $unitCost = (float)$smIng->costPerPakaiForOutlet($outletId);
                }

                // Check if this movement has a deficit / urgent note
                $un = $urgentNotes->firstWhere('ingredient_id', $sm->ingredient_id);
                $deficitQty = 0.0;
                if ($un && (float)$un->pending_qty > 0) {
                    $deficitQty = min($totalQty, (float)$un->pending_qty);
                } elseif (preg_match('/Defisit\s+([0-9.]+)/i', (string)$sm->note, $matches)) {
                    $deficitQty = min($totalQty, (float)$matches[1]);
                }

                $actualQty = max(0.0, $totalQty - $deficitQty);
                $actualCost = round($actualQty * $unitCost, 2);
                $deficitCost = round($deficitQty * $unitCost, 2);

                if ($isPlk) {
                    $cogsPlk += $actualCost;
                    $cogsTeoritisPlk += $deficitCost;
                } else {
                    $cogsBahan += $actualCost;
                    $cogsTeoritisBahan += $deficitCost;
                }
            }
        } else {

            $menu = $t->menu;
            if ($menu) {
                if ($menu->item_type === 'DIRECT' || (!$menu->activeRecipe($t->date) && (float)$menu->cost_price > 0)) {
                    $un = $urgentNotes->firstWhere('menu_id', $menu->id);
                    $reqQty = (int)$t->qty;
                    $qtyToUse = $un ? min($reqQty, (float)$un->deducted_qty) : $reqQty;
                    $shortfallQty = max(0, $reqQty - $qtyToUse);
                    $costPrice = (float)$menu->cost_price;

                    $cogsBahan += $costPrice * $qtyToUse;
                    $cogsTeoritisBahan += $costPrice * $shortfallQty;
                } else {
                    $recipe = $menu->recipes->firstWhere('version', $t->recipe_version) ?: $menu->recipes->first();
                    if ($recipe) {
                        foreach ($recipe->items as $it) {
                            $ing = $it->ingredient;
                            if (!$ing) continue;
                            $isPlk = self::isPerlengkapan($ing);
                            $unitCost = $ing->costPerPakaiForOutlet($outletId);

                            $un = $urgentNotes->firstWhere('ingredient_id', $ing->id);
                            $reqQty = (float)$it->qty * (int)$t->qty;
                            $qtyToUse = $un ? min($reqQty, (float)$un->deducted_qty) : $reqQty;
                            $shortfallQty = max(0.0, $reqQty - $qtyToUse);

                            $itemCost = $qtyToUse * $unitCost;
                            $shortfallCost = $shortfallQty * $unitCost;

                            if ($isPlk) {
                                $cogsPlk += $itemCost;
                                $cogsTeoritisPlk += $shortfallCost;
                            } else {
                                $cogsBahan += $itemCost;
                                $cogsTeoritisBahan += $shortfallCost;
                            }
                        }
                    }
                }
            }

            // Modifiers COGS
            $modifiers = $t->relationLoaded('modifiers') ? $t->modifiers : $t->modifiers()->with('ingredient')->get();
            if ($modifiers && $modifiers->count() > 0) {
                foreach ($modifiers as $mod) {
                    $modIng = $mod->ingredient;
                    if (!$modIng) continue;
                    $isPlk = self::isPerlengkapan($modIng);
                    $unitCost = $modIng->costPerPakaiForOutlet($outletId);
                    $un = $urgentNotes->firstWhere('ingredient_id', $modIng->id);
                    $reqQty = (float)($mod->qty_consumed ?: 1);
                    $qtyToUse = $un ? min($reqQty, (float)$un->deducted_qty) : $reqQty;
                    $shortfallQty = max(0.0, $reqQty - $qtyToUse);

                    $itemCost = $qtyToUse * $unitCost;
                    $shortfallCost = $shortfallQty * $unitCost;

                    if ($isPlk) {
                        $cogsPlk += $itemCost;
                        $cogsTeoritisPlk += $shortfallCost;
                    } else {
                        $cogsBahan += $itemCost;
                        $cogsTeoritisBahan += $shortfallCost;
                    }
                }
            }
        }

        $cogsBahan = round($cogsBahan, 2);
        $cogsPlk = round($cogsPlk, 2);
        $cogsTeoritisBahan = round($cogsTeoritisBahan, 2);
        $cogsTeoritisPlk = round($cogsTeoritisPlk, 2);
        $cogsTeoritis = round($cogsTeoritisBahan + $cogsTeoritisPlk, 2);

        if ($cogsBahan > 0) {
            $lines[] = ['account_code' => '5-51001', 'outlet_id' => $outletId, 'debit' => $cogsBahan, 'credit' => 0, 'memo' => 'Beban Bahan Baku (HPP)'];
            $lines[] = ['account_code' => '1-13001', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $cogsBahan, 'memo' => 'Pengurangan Persediaan Bahan'];
        }
        if ($cogsPlk > 0) {
            $lines[] = ['account_code' => '5-51002', 'outlet_id' => $outletId, 'debit' => $cogsPlk, 'credit' => 0, 'memo' => 'Beban Kemasan & Packaging (HPP)'];
            $lines[] = ['account_code' => '1-13002', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $cogsPlk, 'memo' => 'Pengurangan Persediaan Perlengkapan'];
        }
        if ($cogsTeoritisBahan > 0) {
            $lines[] = ['account_code' => '5-51007', 'outlet_id' => $outletId, 'debit' => $cogsTeoritisBahan, 'credit' => 0, 'memo' => 'Beban Selisih HPP Teoritis Bahan (Stok Minus)'];
            $lines[] = ['account_code' => '1-13001', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $cogsTeoritisBahan, 'memo' => 'Pengurangan Persediaan Bahan (Selisih Teoritis)'];
        }
        if ($cogsTeoritisPlk > 0) {
            $lines[] = ['account_code' => '5-51008', 'outlet_id' => $outletId, 'debit' => $cogsTeoritisPlk, 'credit' => 0, 'memo' => 'Beban Selisih HPP Teoritis Perlengkapan (Stok Minus)'];
            $lines[] = ['account_code' => '1-13002', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $cogsTeoritisPlk, 'memo' => 'Pengurangan Persediaan Perlengkapan (Selisih Teoritis)'];
        }

        return self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $t->date,
            'entry_type'     => 'SALES',
            'reference_type' => 'TRANSACTION',
            'reference_id'   => $t->id,
            'description'    => ($isOpenBillTrx || $isUnpaidOpenBill)
                ? "Penjualan POS Open Bill #{$t->order_number} (Piutang Meja)"
                : "Penjualan POS #{$t->order_number} ({$pm})",
        ], $lines, $bizId);
    }

    /**
     * 2C. POST OPEN BILL SETTLEMENT (Pelunasan Piutang Meja Open Bill)
     * Mencatat penerimaan uang kas/bank/QRIS dan membalik / melunasi akun Piutang Usaha (1-12001)
     * yang terbentuk saat pesanan Open Bill awal dibuat.
     */
    public static function postOpenBillSettlement(Transaction $t): ?JournalEntry
    {
        $bizId = $t->business_id ?? 1;
        $outletId = $t->outlet_id;
        $totalNet = (float)$t->total_price;
        if ($totalNet <= 0) {
            return null;
        }

        $pm = strtoupper(trim((string)$t->payment_method));
        if (empty($pm)) {
            $pm = 'CASH';
        }

        // Jika pelunasan menggunakan Kasbon / Piutang,
        // piutang meja dialihkan ke modul Kasbon (Receivables)
        if (in_array($pm, ['KASBON', 'PIUTANG'])) {
            return null;
        }

        $receiptAcc = self::getAccountCodeForPaymentMethod($pm);

        $lines = [
            [
                'account_code' => $receiptAcc,
                'outlet_id'    => $outletId,
                'debit'        => $totalNet,
                'credit'       => 0,
                'memo'         => "Penerimaan {$pm} Pelunasan Open Bill {$t->order_number}",
            ],
            [
                'account_code' => '1-12001', // PIUTANG USAHA
                'outlet_id'    => $outletId,
                'debit'        => 0,
                'credit'       => $totalNet,
                'memo'         => "Pelunasan Piutang Open Bill {$t->order_number}",
            ],
        ];

        return self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $t->date ?: now()->toDateString(),
            'entry_type'     => 'RECEIVABLE_PAYMENT',
            'reference_type' => 'OPEN_BILL_SETTLEMENT',
            'reference_id'   => $t->id,
            'description'    => "Pelunasan Tagihan Open Bill #{$t->order_number} ({$pm})",
        ], $lines, $bizId);
    }

    /**
     * 2B. POST URGENT NOTE RESOLUTION (Pelunasan Bahan Tergantung)
     * When pending ingredients from urgent notes are verified & deducted later.
     */
    public static function postUrgentNoteResolution(UrgentNote $un): void
    {
        if ($un->status !== 'RESOLVED') {
            return;
        }

        // Jika transaksi asalnya berstatus CANCELLED, VOID, atau REFUNDED,
        // tidak boleh memposting jurnal pelunasan karena tidak ada beban selisih teoritis yang diakui di jurnal penjualan
        if ($un->transaction_id) {
            $parentTrx = Transaction::find($un->transaction_id);
            if ($parentTrx && in_array(strtoupper((string)$parentTrx->status), ['CANCELLED', 'VOID', 'REFUNDED'])) {
                return;
            }
        }

        $bizId = $un->business_id ?: 1;
        $outletId = $un->outlet_id ?: 1;
        $pendingQty = (float)$un->pending_qty;
        if ($pendingQty <= 0) {
            return;
        }
        $cost = 0.0;
        $isPlk = false;

        if ($un->ingredient_id) {
            $ing = $un->ingredient ?: Ingredient::find($un->ingredient_id);
            if ($ing) {
                $isPlk = self::isPerlengkapan($ing);

                // 1. Kunci (Lock) nilai HPP berdasarkan HPP saat transaksi penjualan awal terjadi agar nilai kredit pembalik persis sama dengan nilai debit awal
                $originalUnitCost = 0.0;
                if ($un->transaction_id) {
                    $sm = StockMovement::where('transaction_id', $un->transaction_id)
                        ->where('ingredient_id', $un->ingredient_id)
                        ->first();
                    if ($sm) {
                        if ((float)$sm->cost_before > 0) {
                            $originalUnitCost = (float)$sm->cost_before;
                        } elseif ((float)$sm->cost_after > 0) {
                            $originalUnitCost = (float)$sm->cost_after;
                        } elseif ((float)$sm->unit_price > 0) {
                            $originalUnitCost = (float)$sm->unit_price / max((float)($ing->konversi ?: 1), 1);
                        }
                    }
                }

                $unitCost = ($originalUnitCost > 0) ? $originalUnitCost : $ing->costPerPakaiForOutlet((int)$outletId);
                $cost = round($pendingQty * $unitCost, 2);
            }
        } elseif ($un->menu_id && $un->item_type === 'DIRECT') {
            $menu = $un->menu ?: Menu::find($un->menu_id);
            if ($menu) {
                $cost = round($pendingQty * (float)$menu->cost_price, 2);
            }
        }

        if ($cost <= 0) {
            return;
        }

        $expenseAcc = $isPlk ? '5-51002' : '5-51001';
        $theoreticalAcc = $isPlk ? '5-51008' : '5-51007';
        $expenseMemo = $isPlk ? 'Beban Kemasan & Packaging (Pelunasan HPP Aktual)' : 'Beban Bahan Baku (Pelunasan HPP Aktual)';
        $theoreticalMemo = $isPlk
            ? 'Pengalihan Beban Selisih HPP Teoritis Perlengkapan ke HPP Aktual'
            : 'Pengalihan Beban Selisih HPP Teoritis Bahan ke HPP Aktual';

        $resolveDate = $un->resolved_at
            ? (is_string($un->resolved_at) ? substr($un->resolved_at, 0, 10) : $un->resolved_at->format('Y-m-d'))
            : now()->toDateString();

        $lines = [
            [
                'account_code' => $expenseAcc,
                'outlet_id'    => $outletId,
                'debit'        => $cost,
                'credit'       => 0,
                'memo'         => $expenseMemo,
            ],
            [
                'account_code' => $theoreticalAcc,
                'outlet_id'    => $outletId,
                'debit'        => 0,
                'credit'       => $cost,
                'memo'         => $theoreticalMemo,
            ],
        ];

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $resolveDate,
            'entry_type'     => 'COGS',
            'reference_type' => 'URGENT_NOTE',
            'reference_id'   => $un->id,
            'description'    => "Pelunasan Nota Urgent #{$un->order_number} – {$un->item_name} ({$pendingQty} {$un->unit})",
        ], $lines, $bizId);
    }

    /**
     * 3. POST RECEIVABLE PAYMENT (Pelunasan Kasbon Customer)
     */
    public static function postReceivablePayment(ReceivablePayment $rp): void
    {
        $bizId = $rp->receivable?->business_id ?? 1;
        $outletId = $rp->outlet_id;
        $amount = (float)$rp->amount;
        $pm = strtoupper(trim((string)$rp->payment_method));
        $accCode = self::getAccountCodeForPaymentMethod($pm);
        $payDate = $rp->payment_date ? (is_string($rp->payment_date) ? substr($rp->payment_date, 0, 10) : $rp->payment_date->format('Y-m-d')) : now()->toDateString();

        $lines = [
            [
                'account_code' => $accCode,
                'outlet_id'    => $outletId,
                'debit'        => $amount,
                'credit'       => 0,
                'memo'         => "Penerimaan Pelunasan Kasbon ({$pm})",
            ],
            [
                'account_code' => '1-12001', // PIUTANG USAHA
                'outlet_id'    => $outletId,
                'debit'        => 0,
                'credit'       => $amount,
                'memo'         => "Pelunasan Piutang Kasbon {$rp->payment_no}",
            ],
        ];

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $payDate,
            'entry_type'     => 'RECEIVABLE_PAYMENT',
            'reference_type' => 'RECEIVABLE_PAYMENT',
            'reference_id'   => $rp->id,
            'description'    => "Pelunasan Kasbon #{$rp->payment_no} - {$rp->receivable?->customer_name}",
        ], $lines, $bizId);
    }

    /**
     * Get operating expenses and cash deductions associated with a shift.
     * Intelligent de-duplication ensures legitimate transactions with identical amounts are never dropped.
     */
    public static function calculateShiftExpenses(Shift $shift): array
    {
        $openedAt = $shift->opened_at;
        $closedAt = $shift->closed_at;
        $openedDate = $openedAt ? (is_string($openedAt) ? substr($openedAt, 0, 10) : $openedAt->toDateString()) : now()->toDateString();
        $closedDate = $closedAt ? (is_string($closedAt) ? substr($closedAt, 0, 10) : $closedAt->toDateString()) : now()->toDateString();

        $expenses = [];
        $totalExpenses = 0.0;
        $cashExpenses = 0.0;
        $nonCashExpenses = 0.0;
        $cashOpex = 0.0;
        $cashPurchases = 0.0;
        $cashPayables = 0.0;
        $cashOtherTrx = 0.0;

        // 1. Operating Expenses (OPEX)
        $opexQuery = OperatingExpense::with(['user', 'outlet'])
            ->where(function ($q) use ($shift) {
                $q->where('outlet_id', $shift->outlet_id)
                  ->orWhereNull('outlet_id');
            });

        if ($shift->business_id) {
            $opexQuery->where('business_id', $shift->business_id);
        }

        $opexQuery->where(function ($q) use ($shift, $openedAt, $closedAt, $openedDate, $closedDate) {
            $q->where('shift_id', $shift->id)
              ->orWhere(function ($sub) use ($openedAt, $closedAt, $openedDate, $closedDate) {
                  $sub->whereNull('shift_id');
                  if ($openedAt && $closedAt) {
                      $sub->where(function ($inner) use ($openedAt, $closedAt, $openedDate, $closedDate) {
                          $inner->whereBetween('created_at', [$openedAt, $closedAt])
                                ->orWhereBetween('date', [$openedDate, $closedDate]);
                      });
                  } elseif ($openedAt) {
                      $sub->where(function ($inner) use ($openedAt, $openedDate) {
                          $inner->where('created_at', '>=', $openedAt)
                                ->orWhere('date', '>=', $openedDate);
                      });
                  } else {
                      $sub->whereDate('date', now()->toDateString());
                  }
              });
        });

        $opexList = $opexQuery->orderBy('created_at', 'asc')->get();

        foreach ($opexList as $exp) {
            $amt = (float)$exp->amount;
            $method = strtoupper(trim($exp->payment_method ?? 'CASH'));
            $isCash = in_array($method, ['CASH', 'TUNAI', 'PETTY_CASH', 'KAS_KECIL']) ||
                      str_contains($method, 'CASH') ||
                      str_contains($method, 'TUNAI') ||
                      str_contains($method, 'PETTY') ||
                      str_contains($method, 'KASIR') ||
                      str_contains($method, 'LACI');

            $totalExpenses += $amt;
            if ($isCash) {
                $cashExpenses += $amt;
                $cashOpex += $amt;
            } else {
                $nonCashExpenses += $amt;
            }

            $desc = $exp->name ?: ($exp->category_label ?? 'Biaya Operasional');
            $expenses[] = [
                'id'                   => $exp->id,
                'source'               => 'OPERATING_EXPENSE',
                'expense_no'           => $exp->expense_no,
                'date'                 => $exp->date ? (is_string($exp->date) ? substr($exp->date, 0, 10) : $exp->date->format('Y-m-d')) : null,
                'time'                 => $exp->created_at ? $exp->created_at->format('H:i') : '-',
                'category'             => $exp->category,
                'category_label'       => $exp->category_label ?? $exp->category,
                'name'                 => $desc,
                'description'          => $desc,
                'amount'               => $amt,
                'payment_method'       => $exp->payment_method ?? 'CASH',
                'payment_method_label' => $exp->payment_method_label ?? ($isCash ? 'Tunai / Kas di Laci' : 'Transfer Bank'),
                'is_cash'              => $isCash,
                'notes'                => $exp->notes,
                'user_name'            => $exp->user_name ?? ($exp->user?->name ?? 'Kasir'),
            ];
        }

        // 2. Cash Transactions (Type OUT for CASH_DRAWER / PETTY_CASH)
        $cashTrxQuery = CashTransaction::with(['user', 'outlet'])
            ->where('type', 'OUT')
            ->whereNotIn('category', ['SETORAN_KASIR', 'TARIK_MODAL_KASIR', 'SALDO_AWAL', 'INITIAL_BALANCE', 'INITIAL_BALANCE_CASH', 'SETOR_KAS_APLIKASI', 'SELISIH_SERAH_TERIMA'])
            ->where(function ($q) use ($shift) {
                $q->where('outlet_id', $shift->outlet_id)
                  ->orWhereNull('outlet_id');
            });

        if ($shift->business_id) {
            $cashTrxQuery->where('business_id', $shift->business_id);
        }

        $cashTrxQuery->where(function ($q) use ($shift, $openedAt, $closedAt, $openedDate, $closedDate) {
            $q->where('shift_id', $shift->id)
              ->orWhere(function ($sub) use ($openedAt, $closedAt, $openedDate, $closedDate) {
                  $sub->whereNull('shift_id');
                  if ($openedAt && $closedAt) {
                      $sub->where(function ($inner) use ($openedAt, $closedAt, $openedDate, $closedDate) {
                          $inner->whereBetween('created_at', [$openedAt, $closedAt])
                                ->orWhereBetween('date', [$openedDate, $closedDate]);
                      });
                  } elseif ($openedAt) {
                      $sub->where(function ($inner) use ($openedAt, $openedDate) {
                          $inner->where('created_at', '>=', $openedAt)
                                ->orWhere('date', '>=', $openedDate);
                      });
                  } else {
                      $sub->whereDate('date', now()->toDateString());
                  }
              });
        });

        $cashTrxList = $cashTrxQuery->orderBy('created_at', 'asc')->get();

        foreach ($cashTrxList as $ctx) {
            $ctxDate = $ctx->date ? (is_string($ctx->date) ? substr($ctx->date, 0, 10) : $ctx->date->format('Y-m-d')) : null;
            $amt = (float)$ctx->amount;
            $desc = $ctx->name ?: 'Pengeluaran Kas Kecil';

            // Intelligent de-duplication: ONLY mark duplicate if explicit cross-reference in notes,
            // OR identical normalized name + exact amount + date
            $isDuplicate = false;
            foreach ($expenses as $existing) {
                if ($existing['source'] === 'OPERATING_EXPENSE') {
                    if (!empty($existing['expense_no']) && str_contains((string)$ctx->notes, $existing['expense_no'])) {
                        $isDuplicate = true;
                        break;
                    }
                    if (abs($existing['amount'] - $amt) < 0.01 &&
                        $existing['date'] === $ctxDate &&
                        strcasecmp(trim((string)$existing['name']), trim($desc)) === 0) {
                        $isDuplicate = true;
                        break;
                    }
                }
            }
            if ($isDuplicate) continue;

            $acc = strtoupper(trim((string)$ctx->account));
            $pm = strtoupper(trim((string)$ctx->payment_method));
            $isCash = in_array($acc, ['CASH_DRAWER', 'PETTY_CASH', 'KAS_KECIL', 'KASIR', 'LACI']) ||
                      in_array($pm, ['CASH', 'TUNAI', 'PETTY_CASH', 'KAS_KECIL']) ||
                      str_contains($acc, 'CASH') || str_contains($acc, 'PETTY') ||
                      str_contains($pm, 'CASH') || str_contains($pm, 'TUNAI') ||
                      str_contains($pm, 'PETTY');

            $totalExpenses += $amt;
            if ($isCash) {
                $cashExpenses += $amt;
                $cashOtherTrx += $amt;
            } else {
                $nonCashExpenses += $amt;
            }

            $expenses[] = [
                'id'                   => $ctx->id,
                'source'               => 'CASH_TRANSACTION',
                'expense_no'           => $ctx->transaction_no,
                'date'                 => $ctxDate,
                'time'                 => $ctx->created_at ? $ctx->created_at->format('H:i') : '-',
                'category'             => $ctx->category,
                'category_label'       => $ctx->category_label ?? $ctx->category,
                'name'                 => $desc,
                'description'          => $desc,
                'amount'               => $amt,
                'payment_method'       => $ctx->payment_method ?? 'CASH',
                'payment_method_label' => $isCash ? 'Kas di Laci (Tunai)' : 'Bank / Non-Tunai',
                'is_cash'              => $isCash,
                'notes'                => $ctx->notes,
                'user_name'            => $ctx->user_name ?? ($ctx->user?->name ?? 'Kasir'),
            ];
        }

        // 3. Stock Movements (Direct Cash Ingredient Restock / Pembelian Bahan Tunai dari Laci Kasir)
        $smQuery = StockMovement::with(['ingredient', 'user'])
            ->whereIn('type', ['RESTOCK', 'PURCHASE'])
            ->where(function ($q) use ($shift) {
                $q->where('outlet_id', $shift->outlet_id)
                  ->orWhereNull('outlet_id');
            })
            ->where(function ($q) {
                $q->whereIn('payment_type', ['CASH', 'TUNAI', 'PETTY_CASH', 'KAS_KECIL'])
                  ->orWhere('payment_type', 'like', '%CASH%')
                  ->orWhere('payment_type', 'like', '%TUNAI%')
                  ->orWhere('payment_type', 'like', '%PETTY%');
            });

        if ($shift->business_id) {
            $smQuery->where('business_id', $shift->business_id);
        }

        $smQuery->where(function ($q) use ($shift, $openedAt, $closedAt, $openedDate, $closedDate) {
            $q->where('shift_id', $shift->id)
              ->orWhere(function ($sub) use ($openedAt, $closedAt, $openedDate, $closedDate) {
                  $sub->whereNull('shift_id');
                  if ($openedAt && $closedAt) {
                      $sub->whereBetween('created_at', [$openedAt, $closedAt]);
                  } elseif ($openedAt) {
                      $sub->where('created_at', '>=', $openedAt);
                  } else {
                      $sub->whereDate('date', now()->toDateString());
                  }
              });
        });

        $smList = $smQuery->orderBy('created_at', 'asc')->get();

        foreach ($smList as $sm) {
            $smAmt = (float)($sm->total_price ?: ($sm->qty * $sm->unit_price));
            if ($smAmt <= 0) continue;

            $smDate = $sm->date ? (is_string($sm->date) ? substr($sm->date, 0, 10) : $sm->date->format('Y-m-d')) : null;
            $ingName = $sm->ingredient?->name ?? 'Bahan Baku';
            $desc = "Beli Bahan Tunai: {$ingName} (" . (float)$sm->qty . " " . ($sm->unit ?: ($sm->ingredient?->unit_beli ?: 'satuan')) . ")";

            // Only de-duplicate if explicit cross-reference in note to an existing OPEX
            $isDuplicate = false;
            foreach ($expenses as $existing) {
                if ($existing['source'] === 'OPERATING_EXPENSE') {
                    if (!empty($existing['expense_no']) && str_contains((string)$sm->note, $existing['expense_no'])) {
                        $isDuplicate = true;
                        break;
                    }
                }
            }
            if ($isDuplicate) continue;

            $totalExpenses += $smAmt;
            $cashExpenses += $smAmt;
            $cashPurchases += $smAmt;

            $expenses[] = [
                'id'                   => $sm->id,
                'source'               => 'STOCK_MOVEMENT',
                'expense_no'           => "STK-{$sm->id}",
                'date'                 => $smDate,
                'time'                 => $sm->created_at ? $sm->created_at->format('H:i') : '-',
                'category'             => 'SUPPLIER_PURCHASE',
                'category_label'       => 'Belanja Bahan Baku (Tunai)',
                'name'                 => $desc,
                'description'          => $desc,
                'amount'               => $smAmt,
                'payment_method'       => $sm->payment_type ?? 'CASH',
                'payment_method_label' => 'Kas di Laci (Tunai)',
                'is_cash'              => true,
                'notes'                => $sm->note,
                'user_name'            => $sm->user?->name ?? 'Kasir',
            ];
        }

        // 4. Payable Payments (Pembayaran Hutang Supplier Tunai dari Laci Kasir)
        $ppQuery = PayablePayment::with(['payable', 'payer'])
            ->where(function ($q) use ($shift) {
                $q->where('outlet_id', $shift->outlet_id)
                  ->orWhereNull('outlet_id');
            })
            ->where(function ($q) {
                $q->whereIn('payment_method', ['CASH', 'TUNAI', 'PETTY_CASH', 'KAS_KECIL'])
                  ->orWhere('payment_method', 'like', '%CASH%')
                  ->orWhere('payment_method', 'like', '%TUNAI%')
                  ->orWhere('payment_method', 'like', '%PETTY%');
            });

        if ($shift->business_id) {
            $ppQuery->where('business_id', $shift->business_id);
        }

        $ppQuery->where(function ($q) use ($openedAt, $closedAt, $openedDate, $closedDate) {
            if ($openedAt && $closedAt) {
                $q->whereBetween('created_at', [$openedAt, $closedAt]);
            } elseif ($openedAt) {
                $q->where('created_at', '>=', $openedAt);
            } else {
                $q->whereDate('payment_date', now()->toDateString());
            }
        });

        $ppList = $ppQuery->orderBy('created_at', 'asc')->get();

        foreach ($ppList as $pp) {
            $ppAmt = (float)$pp->amount;
            if ($ppAmt <= 0) continue;

            $ppDate = $pp->payment_date ? (is_string($pp->payment_date) ? substr($pp->payment_date, 0, 10) : $pp->payment_date->format('Y-m-d')) : null;
            $suppName = $pp->payable?->supplier_name ?? 'Supplier';
            $desc = "Bayar Hutang Supplier: {$suppName} (#{$pp->payment_no})";

            // Only de-duplicate if explicit cross-reference in note to an existing OPEX
            $isDuplicate = false;
            foreach ($expenses as $existing) {
                if ($existing['source'] === 'OPERATING_EXPENSE') {
                    if (!empty($existing['expense_no']) && str_contains((string)$pp->notes, $existing['expense_no'])) {
                        $isDuplicate = true;
                        break;
                    }
                }
            }
            if ($isDuplicate) continue;

            $totalExpenses += $ppAmt;
            $cashExpenses += $ppAmt;
            $cashPayables += $ppAmt;

            $expenses[] = [
                'id'                   => $pp->id,
                'source'               => 'PAYABLE_PAYMENT',
                'expense_no'           => $pp->payment_no,
                'date'                 => $ppDate,
                'time'                 => $pp->created_at ? $pp->created_at->format('H:i') : '-',
                'category'             => 'SUPPLIER_PAYMENT',
                'category_label'       => 'Pelunasan Hutang (Tunai)',
                'name'                 => $desc,
                'description'          => $desc,
                'amount'               => $ppAmt,
                'payment_method'       => $pp->payment_method ?? 'CASH',
                'payment_method_label' => 'Kas di Laci (Tunai)',
                'is_cash'              => true,
                'notes'                => $pp->notes,
                'user_name'            => $pp->payer?->name ?? 'Kasir',
            ];
        }

        return [
            'expenses'          => $expenses,
            'total_expenses'    => round($totalExpenses, 2),
            'cash_expenses'     => round($cashExpenses, 2),
            'non_cash_expenses' => round($nonCashExpenses, 2),
            'cash_opex'         => round($cashOpex, 2),
            'cash_purchases'    => round($cashPurchases, 2),
            'cash_payables'     => round($cashPayables, 2),
            'cash_other_trx'    => round($cashOtherTrx, 2),
        ];
    }

    /**
     * Single Source of Truth for calculating expected drawer cash & discrepancy for a shift.
     * Guaranteed 100% consistent across ShiftController and AccountingService.
     */
    public static function calculateShiftDrawerCash(Shift $shift): array
    {
        $initialCash = (float)$shift->initial_cash;
        $openedAt = $shift->opened_at;
        $closedAt = $shift->closed_at;

        // 1. Paid Sales Transactions
        $paidTransactions = $shift->transactions()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->get();

        // 2. Collections from Kasbon / Receivable Payments collected during shift
        $recQuery = ReceivablePayment::where(function ($query) use ($shift, $openedAt, $closedAt) {
            $query->where('shift_id', $shift->id)
                  ->orWhere(function ($q) use ($shift, $openedAt, $closedAt) {
                      $q->whereNull('shift_id')
                        ->where('outlet_id', $shift->outlet_id);
                      if ($openedAt && $closedAt) {
                          $q->whereBetween('created_at', [$openedAt, $closedAt]);
                      } elseif ($openedAt) {
                          $q->where('created_at', '>=', $openedAt);
                      } else {
                          $q->whereDate('payment_date', now()->toDateString());
                      }
                  });
        });
        $shiftRecPayments = $recQuery->get();
        $hasShiftRecPayments = $shiftRecPayments->count() > 0;

        $cashSales = 0.0;
        foreach ($paidTransactions as $t) {
            $method = strtoupper(trim($t->payment_method ?? 'CASH'));
            $price = (float)$t->total_price;
            if ($method === 'CASH' || $method === 'TUNAI') {
                $cashSales += $price;
            } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                if (!$hasShiftRecPayments) {
                    $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                    $dpPaid = (float)($t->amount_paid ?? 0);
                    if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                        $cashSales += $dpPaid;
                    }
                }
            }
        }

        // Add collections from kasbon payments received in cash
        $cashRec = 0.0;
        foreach ($shiftRecPayments as $rp) {
            $rpMethod = strtoupper(trim($rp->payment_method ?? 'CASH'));
            $rpPrice = (float)$rp->amount;
            if ($rpMethod === 'CASH' || $rpMethod === 'TUNAI') {
                $cashSales += $rpPrice;
                $cashRec += $rpPrice;
            }
        }

        // 3. Shift Expenses (OPEX, Stock Purchases, Supplier Debt, Cash Transactions)
        $expData = self::calculateShiftExpenses($shift);
        $cashExpenses = (float)$expData['cash_expenses'];

        $expectedCash = max(0, round($initialCash + $cashSales - $cashExpenses, 2));
        $closingCash = $shift->closing_cash !== null ? (float)$shift->closing_cash : null;
        $diff = $closingCash !== null ? round($closingCash - $expectedCash, 2) : 0.0;

        return [
            'initial_cash'       => $initialCash,
            'cash_sales'         => round($cashSales, 2),
            'cash_receivables'   => round($cashRec, 2),
            'cash_expenses'      => round($cashExpenses, 2),
            'expected_cash'      => $expectedCash,
            'closing_cash'       => $closingCash,
            'cash_difference'    => $diff,
            'exp_data'           => $expData,
        ];
    }

    /**
     * 4. POST SHIFT CLOSING (Selisih Lebih -> Pendapatan Lain-Lain POS, Selisih Kurang -> Biaya Lain-Lain POS)
     */
    public static function postShiftClosing(Shift $shift): void
    {
        $bizId = $shift->business_id ?? 1;

        if ($shift->status !== 'CLOSED' || $shift->closing_cash === null) {
            self::deleteReferenceEntry('SHIFT_CLOSING', $shift->id, $bizId);
            return;
        }

        // Single Source of Truth for expected drawer cash and discrepancy
        $drawerData = self::calculateShiftDrawerCash($shift);
        $diff = $drawerData['cash_difference'];

        // Sinkronkan cash_difference di model shift agar tampilan laporan dan jurnal 100% konsisten
        if ($shift->cash_difference === null || abs((float)$shift->cash_difference - $diff) > 0.01) {
            $shift->updateQuietly(['cash_difference' => $diff]);
        }

        // Jika selisih Rp 0,00, hapus jurnal lama jika ada dan return
        if (abs($diff) < 0.01) {
            self::deleteReferenceEntry('SHIFT_CLOSING', $shift->id, $bizId);
            return;
        }

        $entryDate = $shift->closed_at
            ? (is_string($shift->closed_at) ? substr($shift->closed_at, 0, 10) : $shift->closed_at->format('Y-m-d'))
            : ($shift->opened_at ? (is_string($shift->opened_at) ? substr($shift->opened_at, 0, 10) : $shift->opened_at->format('Y-m-d')) : now()->toDateString());

        $lines = [];

        if ($diff > 0) {
            // Selisih Lebih Kasir: Masuk ke Pendapatan Lain-Lain dari POS (4-41002) dan Kas Kecil Kasir (1-11002) bertambah
            $lines[] = [
                'account_code' => '1-11002', // KAS KECIL KASIR
                'outlet_id'    => $shift->outlet_id,
                'debit'        => $diff,
                'credit'       => 0,
                'memo'         => "Selisih Lebih Kasir Shift #{$shift->id} (POS)",
            ];
            $lines[] = [
                'account_code' => '4-41002', // PENDAPATAN LAIN-LAIN
                'outlet_id'    => $shift->outlet_id,
                'debit'        => 0,
                'credit'       => $diff,
                'memo'         => "Selisih Lebih Kasir Shift #{$shift->id} (POS)",
            ];
            $desc = "Selisih Lebih Kasir Shift #{$shift->id} (POS)";
        } else {
            // Selisih Kurang Kasir: Masuk ke Biaya Lain-Lain dari POS (6-61008) dan Kas Kecil Kasir (1-11002) berkurang
            $shortage = abs($diff);
            $lines[] = [
                'account_code' => '6-61008', // BIAYA LAIN-LAIN
                'outlet_id'    => $shift->outlet_id,
                'debit'        => $shortage,
                'credit'       => 0,
                'memo'         => "Selisih Kurang Kasir Shift #{$shift->id} (POS)",
            ];
            $lines[] = [
                'account_code' => '1-11002', // KAS KECIL KASIR
                'outlet_id'    => $shift->outlet_id,
                'debit'        => 0,
                'credit'       => $shortage,
                'memo'         => "Selisih Kurang Kasir Shift #{$shift->id} (POS)",
            ];
            $desc = "Selisih Kurang Kasir Shift #{$shift->id} (POS)";
        }

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $shift->outlet_id,
            'entry_date'     => $entryDate,
            'entry_type'     => 'SHIFT_CLOSING',
            'reference_type' => 'SHIFT_CLOSING',
            'reference_id'   => $shift->id,
            'description'    => $desc,
        ], $lines, $bizId);
    }

    public static function deleteReferenceEntry(string $referenceType, int $referenceId, ?int $bizId = null): void
    {
        $q = JournalEntry::where('reference_type', $referenceType)->where('reference_id', $referenceId);
        if ($bizId) {
            $q->where('business_id', $bizId);
        }
        $q->delete();
    }

    /**
     * 5. POST CASH TRANSACTION (Setoran Kasir, Injeksi Modal, Prive, Mutasi Kas)
     */
    public static function postCashTransaction(CashTransaction $ct): void
    {
        $bizId = $ct->business_id ?? 1;
        $outletId = $ct->outlet_id;
        $amount = (float)$ct->amount;
        $date = $ct->date ?: now()->toDateString();
        $cat = strtoupper(trim((string)$ct->category));
        $acc = strtoupper(trim((string)$ct->account));
        $status = strtoupper(trim((string)($ct->status ?? 'APPROVED')));

        // If status is PENDING or REJECTED, do not post journal and delete any previous entry
        if ($status === 'PENDING' || $status === 'REJECTED') {
            self::deleteReferenceEntry('CASH_TRANSACTION', $ct->id, $bizId);
            return;
        }

        $lines = [];

        if ($cat === 'SETORAN_KASIR' || $cat === 'CASH_DEPOSIT') {
            // Debit Kas Besar / Bank, Kredit Kas Kecil (Kasir)
            $destCode = ($acc === 'BANK_MAIN' || str_contains($acc, 'BANK')) ? '1-11003' : '1-11001';
            $lines[] = ['account_code' => $destCode, 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Penerimaan Setoran Kasir (Disetujui)'];
            $lines[] = ['account_code' => '1-11002', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Setoran Uang Kasir (Disetujui)'];
        } elseif ($cat === 'TARIK_MODAL_KASIR') {
            // Pengambilan Modal Kasir dari Kas Besar / Bank: Kasir (1-11002) Debit, Kas Besar (1-11001) Kredit
            $srcCode = ($acc === 'BANK_MAIN' || str_contains($acc, 'BANK')) ? '1-11003' : '1-11001';
            $lines[] = ['account_code' => '1-11002', 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Penerimaan Modal Awal Kasir Shift #{$ct->shift_id}"];
            $lines[] = ['account_code' => $srcCode,    'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Pengambilan Kas Besar untuk Modal Kasir Shift #{$ct->shift_id}"];
        } elseif ($cat === 'SALDO_AWAL' || $cat === 'INITIAL_BALANCE' || $cat === 'INITIAL_BALANCE_CASH') {
            // Saldo Awal Modal Kasir Pertama Kali: Jika sudah dibukukan via INITIAL_BALANCE_CASH, jangan buat jurnal ganda
            $hasInitBalanceJournal = JournalEntry::where('business_id', $bizId)
                ->where('outlet_id', $outletId)
                ->where('reference_type', 'INITIAL_BALANCE_CASH')
                ->exists();

            if ($hasInitBalanceJournal) {
                self::deleteReferenceEntry('CASH_TRANSACTION', $ct->id, $bizId);
                return;
            }

            $destCode = ($acc === 'BANK_MAIN' || str_contains($acc, 'BANK')) ? '1-11003' : ($acc === 'KAS_BESAR' ? '1-11001' : '1-11002');
            $lines[] = ['account_code' => $destCode, 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Saldo Awal Modal Kasir Shift #{$ct->shift_id}"];
            $lines[] = ['account_code' => '3-31001',  'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Modal Awal Pemilik Disetor (Shift #{$ct->shift_id})"];
        } elseif ($cat === 'CAPITAL_INJECTION') {
            // Injeksi Modal: Debit Kas/Bank, Kredit Modal Disetor (3-31001)
            $destCode = ($acc === 'BANK_MAIN' || str_contains($acc, 'BANK')) ? '1-11003' : ($acc === 'KAS_BESAR' ? '1-11001' : '1-11002');
            $lines[] = ['account_code' => $destCode, 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Penerimaan Injeksi Modal'];
            $lines[] = ['account_code' => '3-31001', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Modal Pemilik Disetor'];
        } elseif ($cat === 'OWNER_WITHDRAWAL' || $cat === 'PRIVE') {
            // Prive: Debit Prive (3-31002), Kredit Kas/Bank
            $srcCode = ($acc === 'BANK_MAIN' || str_contains($acc, 'BANK')) ? '1-11003' : ($acc === 'KAS_BESAR' ? '1-11001' : '1-11002');
            $lines[] = ['account_code' => '3-31002', 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Prive Penarikan Owner'];
            $lines[] = ['account_code' => $srcCode, 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Pengeluaran Kas/Bank Prive'];
        } elseif ($cat === 'CAPEX_PURCHASE') {
            // Belanja Pemeliharaan / Aset Operasional
            $srcCode = ($acc === 'BANK_MAIN' || str_contains($acc, 'BANK')) ? '1-11003' : ($acc === 'KAS_BESAR' ? '1-11001' : '1-11002');
            $lines[] = ['account_code' => '6-61005', 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Biaya Pemeliharaan & Aset'];
            $lines[] = ['account_code' => $srcCode, 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Pembayaran Kas/Bank'];
        } elseif ($cat === 'SETOR_KAS_APLIKASI' || $cat === 'SETORAN_QRIS' || $cat === 'SETORAN_KAS_APLIKASI' || $cat === 'DEPOSIT_KAS_APLIKASI') {
            // Setoran Kas Aplikasi ke Rekening Bank: Bank (1-11003) Debit, Kas Aplikasi (1-11004) Kredit, Pendapatan Lain-Lain (4-41002) Kredit (jika ada selisih lebih)
            // Biaya Admin Kas Aplikasi (6-61010) Debit (jika ada potongan fee/MDR)
            $destCode = ($acc === 'KAS_BESAR' ? '1-11001' : '1-11003');
            
            $excess = 0;
            if (preg_match('/\[SPLIT_PENDAPATAN_LAIN:([0-9.]+)\]/', (string)$ct->notes, $matches)) {
                $excess = round((float)$matches[1], 2);
            }

            $adminFee = 0;
            if (preg_match('/\[(?:BIAYA_ADMIN|ADMIN_FEE):([0-9.]+)\]/', (string)$ct->notes, $feeMatches)) {
                $adminFee = round((float)$feeMatches[1], 2);
            }

            if ($adminFee > 0) {
                $netBank = max(0, round($amount - $adminFee, 2));
                $lines[] = ['account_code' => $destCode, 'outlet_id' => $outletId, 'debit' => $netBank, 'credit' => 0, 'memo' => "Penerimaan Bersih Kas Aplikasi ke Bank {$ct->account}"];
                $lines[] = ['account_code' => '6-61010',  'outlet_id' => $outletId, 'debit' => $adminFee, 'credit' => 0, 'memo' => "Biaya Admin Kas Aplikasi / MDR ke Bank {$ct->account}"];
                $lines[] = ['account_code' => '1-11004',  'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Penyetoran Saldo Kas Aplikasi ke Bank {$ct->account}"];
            } elseif ($excess > 0 && $excess < $amount) {
                $kasAplikasiPortion = round($amount - $excess, 2);
                $lines[] = ['account_code' => $destCode, 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Penerimaan Setoran Kas Aplikasi ke Bank {$ct->account}"];
                if ($kasAplikasiPortion > 0) {
                    $lines[] = ['account_code' => '1-11004', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $kasAplikasiPortion, 'memo' => "Penyetoran Saldo Kas Aplikasi ke Bank {$ct->account}"];
                }
                $lines[] = ['account_code' => '4-41002', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $excess, 'memo' => "Selisih Lebih Pencairan Kas Aplikasi ke Bank (Pendapatan Lain-Lain)"];
            } else {
                $lines[] = ['account_code' => $destCode,   'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Penerimaan Setoran Kas Aplikasi ke Bank {$ct->account}"];
                $lines[] = ['account_code' => '1-11004',   'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Penyetoran Kas Aplikasi (QRIS) ke Bank {$ct->account}"];
            }
        } elseif (str_contains($cat, 'KARYAWAN') || str_contains($cat, 'KASBON_STAFF')) {
            // Kasbon Karyawan (dialihkan ke 1-12001 Piutang Usaha)
            $srcCode = ($acc === 'BANK_MAIN' || str_contains($acc, 'BANK')) ? '1-11003' : ($acc === 'KAS_BESAR' ? '1-11001' : '1-11002');
            $lines[] = ['account_code' => '1-12001', 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Piutang Kasbon Karyawan'];
            $lines[] = ['account_code' => $srcCode, 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Pengeluaran Kas Kasbon Karyawan'];
        } elseif ($cat === 'SELISIH_SERAH_TERIMA' || $cat === 'SELISIH_KAS') {
            // Selisih Serah Terima Laci (Handover Discrepancy antar-shift):
            // Jika OUT (Kurang): Debit Beban Biaya Lain-Lain (6-61008), Kredit Kas Kecil Kasir (1-11002)
            // Jika IN (Lebih): Debit Kas Kecil Kasir (1-11002), Kredit Pendapatan Lain-Lain (4-41002)
            $cashCode = '1-11002';
            if ($ct->type === 'OUT') {
                $lines[] = ['account_code' => '6-61008', 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Beban Selisih Kurang Serah Terima Kasir Shift #{$ct->shift_id}"];
                $lines[] = ['account_code' => $cashCode,  'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Pengurangan Kas Laci Serah Terima Shift #{$ct->shift_id}"];
            } else {
                $lines[] = ['account_code' => $cashCode,  'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Penambahan Kas Laci Serah Terima Shift #{$ct->shift_id}"];
                $lines[] = ['account_code' => '4-41002', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Pendapatan Selisih Lebih Serah Terima Kasir Shift #{$ct->shift_id}"];
            }
        }

        if (!empty($lines)) {
            self::recordEntry([
                'business_id'    => $bizId,
                'outlet_id'      => $outletId,
                'entry_date'     => $date,
                'entry_type'     => $cat,
                'reference_type' => 'CASH_TRANSACTION',
                'reference_id'   => $ct->id,
                'description'    => "Transaksi Kas #{$ct->transaction_no} - {$ct->name}",
            ], $lines, $bizId);
        }
    }

    /**
     * 6. POST OPERATING EXPENSE (OPEX)
     */
    public static function postOperatingExpense(OperatingExpense $oe): void
    {
        $bizId = $oe->business_id ?? 1;
        $outletId = $oe->outlet_id;
        $amount = (float)$oe->amount;
        $date = $oe->date ?: now()->toDateString();
        $cat = strtoupper(trim((string)$oe->category));

        // Map category to OPEX account code based on COA
        $expCode = null;

        // 1. Cek apakah kategori merupakan Kode Akun COA langsung (misal '6-61002' atau '6-61011')
        if (preg_match('/^[0-9]+-[0-9]+(\.[0-9]+)?$/', $cat)) {
            $expCode = explode('.', $cat)[0];
        }

        // 2. Jika bukan kode langsung, cocokkan dengan pola kata atau legacy key
        if (!$expCode) {
            if (str_contains($cat, 'GAJI') || str_contains($cat, 'SALARY') || str_contains($cat, 'UPAH') || str_contains($cat, 'KARYAWAN')) {
                $expCode = '6-61001'; // BIAYA GAJI KARYAWAN
            } elseif (str_contains($cat, 'LISTRIK') || str_contains($cat, 'PLN')) {
                $expCode = '6-61002'; // BIAYA LISTRIK
            } elseif (str_contains($cat, 'AIR') || str_contains($cat, 'PDAM')) {
                $expCode = '6-61003'; // BIAYA AIR
            } elseif (str_contains($cat, 'GAS') || str_contains($cat, 'ELPIJI') || str_contains($cat, 'LPG')) {
                $expCode = '6-61004'; // BIAYA GAS
            } elseif (str_contains($cat, 'SEWA') || str_contains($cat, 'RENT') || str_contains($cat, 'KONTRAK') || str_contains($cat, 'GEDUNG') || str_contains($cat, 'BANGUNAN')) {
                $expCode = '6-61011'; // BIAYA SEWA TEMPAT / BANGUNAN
            } elseif (str_contains($cat, 'PEMELIHARAAN') || str_contains($cat, 'MAINTENANCE') || str_contains($cat, 'SERVIS') || str_contains($cat, 'PERBAIKAN') || str_contains($cat, 'RENOVASI') || str_contains($cat, 'SANITASI')) {
                $expCode = '6-61005'; // BIAYA PEMELIHARAAN ASET
            } elseif (str_contains($cat, 'MARKETING') || str_contains($cat, 'PROMOSI') || str_contains($cat, 'IKLAN') || str_contains($cat, 'ADS')) {
                $expCode = '6-61006'; // BIAYA MARKETING
            } elseif (str_contains($cat, 'LOGISTIK') || str_contains($cat, 'TRANSPORT') || str_contains($cat, 'ONGKIR') || str_contains($cat, 'BENSIN') || str_contains($cat, 'PENGIRIMAN')) {
                $expCode = '6-61007'; // BIAYA LOGISTIK & TRANSPORTASI
            } elseif (str_contains($cat, 'ADMIN') || str_contains($cat, 'APLIKASI') || str_contains($cat, 'MDR') || str_contains($cat, 'GATEWAY') || str_contains($cat, 'FEE')) {
                $expCode = '6-61010'; // BIAYA ADMIN KAS APLIKASI
            } elseif (str_contains($cat, 'UTILITIES')) {
                $expCode = '6-61002'; // Default utilities ke listrik
            } elseif ((str_contains($cat, 'SELISIH') && str_contains($cat, 'KAS')) || str_contains($cat, 'SHORTAGE') || str_contains($cat, 'TEKOR')) {
                $expCode = '6-61008'; // BIAYA LAIN-LAIN
            } else {
                $expCode = '6-61009'; // BIAYA OPERASIONAL LAINNYA
            }
        }

        // Source account (Kas Kecil Kasir / Kas Besar / Bank)
        $pm = strtoupper(trim((string)$oe->payment_method));
        $srcCode = self::getAccountCodeForPaymentMethod($pm);

        $lines = [
            ['account_code' => $expCode, 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Beban {$oe->name}"],
            ['account_code' => $srcCode, 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Pembayaran Beban via {$pm}"],
        ];

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $date,
            'entry_type'     => 'OPEX',
            'reference_type' => 'OPERATING_EXPENSE',
            'reference_id'   => $oe->id,
            'description'    => "Beban Operasional #{$oe->expense_no} - {$oe->name}",
        ], $lines, $bizId);
    }

    /**
     * 7. POST TRANSFER ANTAR CABANG (Inter-branch Stock Transfer)
     */
    public static function postTransfer(Transfer $trf): void
    {
        if (!in_array($trf->status, ['SENT', 'RECEIVED', 'COMPLETED', 'APPROVED', 'PARTIALLY_RETURNED', 'RETURNED'])) {
            JournalEntry::where('reference_type', 'TRANSFER')
                ->where('reference_id', $trf->id)
                ->delete();
            return;
        }

        $bizId = $trf->business_id ?? 1;
        $srcOutletId = $trf->source_outlet_id ?? $trf->from_outlet_id;
        $destOutletId = $trf->destination_outlet_id ?? $trf->to_outlet_id;
        $date = $trf->date ? (is_string($trf->date) ? substr($trf->date, 0, 10) : $trf->date->format('Y-m-d')) : now()->toDateString();

        $totalValBahan = 0.0;
        $totalValPlk = 0.0;

        foreach ($trf->items as $it) {
            $ing = $it->ingredient;
            if (!$ing) continue;
            $isPlk = self::isPerlengkapan($ing);
            $costUnit = (float)($it->unit_cost ?: ($srcOutletId ? $ing->costPerPakaiForOutlet($srcOutletId) : $ing->cost_per_pakai));
            
            // Tentukan kuantitas transfer aktual yang diterima/berpindah ke cabang tujuan
            $origInput = (float)($it->input_qty ?: $it->qty);
            $ratio = ($origInput > 0) ? ((float)$it->qty / $origInput) : 1.0;

            $qtyToTransfer = (float)$it->qty;
            if ($it->received_qty !== null) {
                $qtyToTransfer = round((float)$it->received_qty * $ratio, 4);
            } elseif ($trf->status === 'RETURNED' && $trf->return_disposition === 'RETURN_TO_SOURCE') {
                $qtyToTransfer = 0.0;
            }

            // Jika item memiliki total_price dan kuantitas diterima penuh, gunakan total_price item untuk mencegah selisih pembulatan desimal
            if ($origInput > 0 && $it->received_qty !== null && abs((float)$it->received_qty - $origInput) < 0.0001 && (float)$it->total_price > 0) {
                $itemVal = (float)$it->total_price;
            } elseif ($it->received_qty === null && (float)$it->total_price > 0) {
                $itemVal = (float)$it->total_price;
            } else {
                $itemVal = $qtyToTransfer * $costUnit;
            }

            if ($isPlk) {
                $totalValPlk += $itemVal;
            } else {
                $totalValBahan += $itemVal;
            }
        }

        $totalVal = round($totalValBahan + $totalValPlk, 2);
        if ($totalVal <= 0) {
            JournalEntry::where('reference_type', 'TRANSFER')
                ->where('reference_id', $trf->id)
                ->delete();
            return;
        }

        // A. Jurnal Cabang Pengirim (Source Outlet - Kredit Pengurangan Stok)
        $linesSrc = [];
        if ($totalValBahan > 0) {
            $linesSrc[] = ['account_code' => '1-13001', 'outlet_id' => $srcOutletId, 'debit' => 0, 'credit' => $totalValBahan, 'memo' => 'Pengurangan Stok Bahan Keluar'];
        }
        if ($totalValPlk > 0) {
            $linesSrc[] = ['account_code' => '1-13002', 'outlet_id' => $srcOutletId, 'debit' => 0, 'credit' => $totalValPlk, 'memo' => 'Pengurangan Stok Perlengkapan Keluar'];
        }

        // B. Jurnal Cabang Penerima (Destination Outlet - Debit Penambahan Stok)
        $linesDest = [];
        if ($totalValBahan > 0) {
            $linesDest[] = ['account_code' => '1-13001', 'outlet_id' => $destOutletId, 'debit' => $totalValBahan, 'credit' => 0, 'memo' => 'Penambahan Stok Bahan Masuk'];
        }
        if ($totalValPlk > 0) {
            $linesDest[] = ['account_code' => '1-13002', 'outlet_id' => $destOutletId, 'debit' => $totalValPlk, 'credit' => 0, 'memo' => 'Penambahan Stok Perlengkapan Masuk'];
        }

        $allLines = array_merge($linesDest, $linesSrc);

        $srcName = $trf->sourceOutlet?->name ?? ($trf->fromOutlet?->name ?? 'Cabang Asal');
        $destName = $trf->destinationOutlet?->name ?? ($trf->toOutlet?->name ?? 'Cabang Tujuan');

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $srcOutletId ?: $destOutletId,
            'entry_date'     => $date,
            'entry_type'     => 'TRANSFER',
            'reference_type' => 'TRANSFER',
            'reference_id'   => $trf->id,
            'description'    => "Transfer Stok #{$trf->transfer_no} ({$srcName} -> {$destName})",
        ], $allLines, $bizId);
    }

    /**
     * 8. POST WASTE / BAHAN TERBUANG & RUSAK
     */
    public static function postWaste(WasteLog $wl): void
    {
        $bizId = $wl->business_id ?? 1;
        $outletId = $wl->outlet_id;
        $lossCost = (float)$wl->loss_cost;
        if ($lossCost <= 0) return;

        $date = $wl->date ?: now()->toDateString();
        $ing = $wl->ingredient;
        $menu = $wl->menu;
        $memoReason = $wl->reason_label ?: ($wl->reason ?: ($wl->notes ?: 'Waste/Kerugian Stok'));

        $lines = [];

        $orderNum = null;
        if (preg_match('/(?:Nota|Open Bill|Tagihan)?\s*#(TRX-[A-Za-z0-9\-]+|[A-Za-z0-9\-]+)/i', (string)$wl->notes, $matches)) {
            $orderNum = $matches[1];
        }

        // Jika WasteLog ini berasal dari pembatalan transaksi yang sudah PAID (Void Wasted pada transaksi yang sudah lunas),
        // jurnal biaya waste sudah dicatat langsung secara terintegrasi pada Jurnal Void Transaksi (VOID_TRANSACTION).
        // Tetapi untuk transaksi Open Bill (HOLD) yang di-void sebelum bayar, jurnal VOID_TRANSACTION tidak dibuat,
        // sehingga WasteLog inilah yang mencatat jurnal kerugian waste-nya secara resmi!
        if ($orderNum) {
            $hasVoidTrxJournal = JournalEntry::where('business_id', $bizId)
                ->where('reference_type', 'VOID_TRANSACTION')
                ->where('description', 'like', "%{$orderNum}%")
                ->whereHas('lines', fn($l) => $l->where('account_code', 'like', '5-51003%'))
                ->exists();
            if ($hasVoidTrxJournal) {
                JournalEntry::where('business_id', $bizId)
                    ->where('reference_type', 'WASTE_LOG')
                    ->where('reference_id', $wl->id)
                    ->delete();
                return;
            }
        }

        $movements = null;
        if ($wl->stock_movement_id) {
            $movements = StockMovement::where('id', $wl->stock_movement_id)->with('ingredient')->get();
        } elseif ($orderNum) {
            $movements = StockMovement::where('type', 'WASTE')
                ->where(function($q) use ($orderNum) {
                    $q->where('note', 'like', "%{$orderNum}%");
                })
                ->when($wl->menu, function($q) use ($wl) {
                    $q->where('note', 'like', "%{$wl->menu->name}%");
                })
                ->with('ingredient')
                ->get();
            if ($movements->isEmpty()) {
                $movements = StockMovement::where('type', 'WASTE')
                    ->where('note', 'like', "%{$orderNum}%")
                    ->with('ingredient')
                    ->get();
            }
        } elseif ($wl->waste_no) {
            $movements = StockMovement::where('type', 'WASTE')
                ->where('note', 'like', "%{$wl->waste_no}%")
                ->with('ingredient')
                ->get();
        }

        if ($movements && $movements->count() > 0) {
            $wasteBahan = 0.0;
            $wastePlk = 0.0;
            foreach ($movements as $m) {
                $mIng = $m->ingredient;
                $isPlk = self::isPerlengkapan($mIng);
                $mCost = (float)$m->total_price;
                if ($mCost <= 0) {
                    $unitCost = (float)$m->unit_price > 0 ? (float)$m->unit_price : ($mIng ? (float)$mIng->costPerPakaiForOutlet((int)$outletId) : 0);
                    $mCost = round((float)$m->qty * $unitCost, 2);
                }
                if ($isPlk) {
                    $wastePlk += $mCost;
                } else {
                    $wasteBahan += $mCost;
                }
            }
            $wasteBahan = round($wasteBahan, 2);
            $wastePlk = round($wastePlk, 2);

            $itemName = $menu ? $menu->name : ($ing ? $ing->name : 'Menu/Bahan');

            if ($wasteBahan > 0) {
                $lines[] = ['account_code' => '5-51003', 'outlet_id' => $outletId, 'debit' => $wasteBahan, 'credit' => 0, 'memo' => "Beban Kerugian Waste Bahan ({$itemName}): {$memoReason}"];
                $lines[] = ['account_code' => '1-13001', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $wasteBahan, 'memo' => "Pengurangan Persediaan Bahan Baku ({$itemName})"];
            }
            if ($wastePlk > 0) {
                $lines[] = ['account_code' => '5-51006', 'outlet_id' => $outletId, 'debit' => $wastePlk, 'credit' => 0, 'memo' => "Beban Kerugian Waste Perlengkapan/Kemasan ({$itemName}): {$memoReason}"];
                $lines[] = ['account_code' => '1-13002', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $wastePlk, 'memo' => "Pengurangan Persediaan Perlengkapan ({$itemName})"];
            }
        } elseif ($ing) {
            // Direct ingredient waste (fallback when no stock movement found)
            $isPlk = self::isPerlengkapan($ing);
            $invAcc = $isPlk ? '1-13002' : '1-13001';
            $wasteExpAcc = $isPlk ? '5-51006' : '5-51003';
            $memoType = $isPlk ? 'Perlengkapan' : 'Bahan Baku';

            $unitCost = method_exists($ing, 'costPerPakaiForOutlet') 
                ? (float)$ing->costPerPakaiForOutlet((int)$outletId) 
                : (float)($ing->harga / max(1, (float)$ing->konversi));
            
            $computedCost = round((float)($wl->qty_pakai ?: $wl->qty ?: 1) * $unitCost, 2);
            $lossCost = $computedCost > 0 ? $computedCost : $lossCost;

            $lines = [
                ['account_code' => $wasteExpAcc, 'outlet_id' => $outletId, 'debit' => $lossCost, 'credit' => 0, 'memo' => "Beban Kerugian Waste {$memoType} ({$ing->name}): {$memoReason}"],
                ['account_code' => $invAcc,      'outlet_id' => $outletId, 'debit' => 0, 'credit' => $lossCost, 'memo' => "Pengurangan Persediaan {$memoType} ({$ing->name})"],
            ];
        } elseif ($menu) {
            // Menu / Recipe waste: calculate exact cost from active recipe moving average prices
            $wasteBahan = 0.0;
            $wastePlk = 0.0;
            $qtyMenu = (float)($wl->qty_pakai ?: $wl->qty ?: 1);
            $recipe = $menu->recipes?->sortByDesc('version')->first() ?: $menu->recipes?->first();

            if ($recipe && $recipe->items && $recipe->items->count() > 0) {
                foreach ($recipe->items as $it) {
                    $itemIng = $it->ingredient;
                    if (!$itemIng) continue;
                    $isPlk = self::isPerlengkapan($itemIng);
                    $unitCost = method_exists($itemIng, 'costPerPakaiForOutlet') 
                        ? (float)$itemIng->costPerPakaiForOutlet((int)$outletId) 
                        : (float)($itemIng->harga / max(1, (float)$itemIng->konversi));
                    $itemCost = round((float)$it->qty * $unitCost * $qtyMenu, 2);

                    if ($isPlk) {
                        $wastePlk += $itemCost;
                    } else {
                        $wasteBahan += $itemCost;
                    }
                }

                $wasteBahan = round($wasteBahan, 2);
                $wastePlk = round($wastePlk, 2);
            }

            if ($wasteBahan <= 0 && $wastePlk <= 0) {
                $wasteBahan = $lossCost;
            }

            if ($wasteBahan > 0) {
                $lines[] = ['account_code' => '5-51003', 'outlet_id' => $outletId, 'debit' => $wasteBahan, 'credit' => 0, 'memo' => "Beban Kerugian Waste Bahan ({$menu->name}): {$memoReason}"];
                $lines[] = ['account_code' => '1-13001', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $wasteBahan, 'memo' => "Pengurangan Persediaan Bahan Baku ({$menu->name})"];
            }
            if ($wastePlk > 0) {
                $lines[] = ['account_code' => '5-51006', 'outlet_id' => $outletId, 'debit' => $wastePlk, 'credit' => 0, 'memo' => "Beban Kerugian Waste Perlengkapan/Kemasan ({$menu->name}): {$memoReason}"];
                $lines[] = ['account_code' => '1-13002', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $wastePlk, 'memo' => "Pengurangan Persediaan Perlengkapan ({$menu->name})"];
            }
        } else {
            // Fallback
            $lines = [
                ['account_code' => '5-51003', 'outlet_id' => $outletId, 'debit' => $lossCost, 'credit' => 0, 'memo' => "Beban Kerugian Waste: {$memoReason}"],
                ['account_code' => '1-13001', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $lossCost, 'memo' => 'Pengurangan Persediaan Bahan Rusak/Basi'],
            ];
        }

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $date,
            'entry_type'     => 'WASTE',
            'reference_type' => 'WASTE_LOG',
            'reference_id'   => $wl->id,
            'description'    => "Waste Log #{$wl->id} - {$memoReason}",
        ], $lines, $bizId);
    }

    public static function postWasteLog(WasteLog $wl): void
    {
        self::postWaste($wl);
    }

    /**
     * 9. POST STOCK OPNAME (Penyesuaian Fisik Opname)
     */
    public static function postOpname(Opname $op): void
    {
        $bizId = $op->business_id ?? 1;
        $outletId = $op->outlet_id;
        $diffVal = (float)$op->variance_value;
        if (abs($diffVal) <= 0.01) return;

        $date = $op->opname_date ?: now()->toDateString();
        $ing = $op->ingredient;
        $isPlk = $ing ? self::isPerlengkapan($ing) : false;
        $invAcc = $isPlk ? '1-13002' : '1-13001';
        $deficitAcc = $isPlk ? '5-51006' : '5-51003'; // 5-51006 Waste Perlengkapan, 5-51003 Waste Bahan / Defisit

        $lines = [];
        if ($diffVal < 0) {
            // Defisit (Kurang): Beban Selisih Kurang Opname (D), Persediaan (K)
            $shortAmt = abs($diffVal);
            $lines[] = ['account_code' => $deficitAcc, 'outlet_id' => $outletId, 'debit' => $shortAmt, 'credit' => 0, 'memo' => "Defisit Opname {$ing?->name}"];
            $lines[] = ['account_code' => $invAcc,     'outlet_id' => $outletId, 'debit' => 0, 'credit' => $shortAmt, 'memo' => 'Penyesuaian Fisik Opname (Kurang)'];
        } else {
            // Surplus (Lebih): Persediaan (D), Pendapatan Lain-Lain Surplus Opname (K)
            $lines[] = ['account_code' => $invAcc,     'outlet_id' => $outletId, 'debit' => $diffVal, 'credit' => 0, 'memo' => 'Penyesuaian Fisik Opname (Surplus)'];
            $lines[] = ['account_code' => '4-41002',   'outlet_id' => $outletId, 'debit' => 0, 'credit' => $diffVal, 'memo' => "Surplus Opname {$ing?->name}"];
        }

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $date,
            'entry_type'     => 'OPNAME',
            'reference_type' => 'OPNAME',
            'reference_id'   => $op->id,
            'description'    => "Penyesuaian Opname Fisik #{$op->opname_no} - {$ing?->name}",
        ], $lines, $bizId);
    }

    /**
     * 10. POST STOCK PURCHASES & RESTOCK (Pembelian Stok Tunai / Hutang)
     */
    public static function postStockMovementPurchase(StockMovement $sm): void
    {
        if (!in_array($sm->type, ['PURCHASE', 'RESTOCK', 'IN'])) {
            return;
        }

        $bizId = $sm->business_id ?? 1;
        $outletId = $sm->outlet_id;
        $ing = $sm->ingredient ?: Ingredient::find($sm->ingredient_id);
        $konversi = $ing ? max((float)$ing->konversi, 1) : 1;

        $amount = (float)$sm->total_price > 0
            ? (float)$sm->total_price
            : round((float)$sm->qty * (float)($sm->unit_price > 0 ? ($sm->unit_price > 1000 && $konversi > 1 ? $sm->unit_price / $konversi : $sm->unit_price) : ($ing ? $ing->costPerPakaiForOutlet($outletId) : 0)), 2);

        if ($amount <= 0) {
            return;
        }

        $date = $sm->date ?: now()->toDateString();
        $isPlk = self::isPerlengkapan($ing);
        $invAcc = $isPlk ? '1-13002' : '1-13001';

        $payType = strtoupper(trim((string)$sm->payment_type));
        $isHutang = ($payType === 'CREDIT' || $payType === 'HUTANG' || $payType === 'TEMPO' || $sm->payable_id);
        $credAcc = $isHutang ? '2-21001' : self::getAccountCodeForPaymentMethod($payType);

        $lines = [
            ['account_code' => $invAcc,  'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Pembelian {$ing?->name}"],
            ['account_code' => $credAcc, 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Pembayaran/Hutang Pembelian ({$payType}) {$sm->note}"],
        ];

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $date,
            'entry_type'     => 'PURCHASE',
            'reference_type' => 'STOCK_MOVEMENT',
            'reference_id'   => $sm->id,
            'description'    => "Pembelian Stok #{$sm->id} - {$ing?->name}",
        ], $lines, $bizId);
    }

    /**
     * 10B. POST STOCK ADJUSTMENT / NORMALISASI STOK MINUS (Pembalikan HPP Teoritis atau Surplus/Defisit Opname Manual)
     */
    public static function postStockMovementAdjustment(StockMovement $sm): void
    {
        if (!in_array($sm->type, ['ADJUSTMENT_IN', 'ADJUSTMENT_PLUS', 'ADJUSTMENT_OUT'])) {
            return;
        }

        // Jika mutasi berasal dari Opname fisik yang sudah dijurnal lewat postOpname, lewati agar tidak dobel jurnal
        $note = strtolower($sm->note ?? '');
        if (str_contains($note, 'opname fisik') || str_contains($note, 'penyesuaian opname')) {
            return;
        }

        $bizId = $sm->business_id ?? 1;
        $outletId = $sm->outlet_id;
        $ing = $sm->ingredient ?: Ingredient::find($sm->ingredient_id);
        $konversi = $ing ? max((float)$ing->konversi, 1) : 1;

        $amount = (float)$sm->total_price;
        if ($amount <= 0 && $ing) {
            $amount = round((float)$sm->qty * (float)($sm->unit_price > 0 ? ($sm->unit_price > 1000 && $konversi > 1 ? $sm->unit_price / $konversi : $sm->unit_price) : $ing->costPerPakaiForOutlet($outletId)), 2);
        }
        if ($amount <= 0) {
            return;
        }

        $date = $sm->date ?: now()->toDateString();
        $isPlk = self::isPerlengkapan($ing);
        $invAcc = $isPlk ? '1-13002' : '1-13001';
        $ingName = $ing ? $ing->name : 'Bahan';

        $isNormalisasi = str_contains($note, 'normalisasi') || str_contains($note, 'reset stok minus') || str_contains($note, 'barang baru');

        $lines = [];

        if (in_array($sm->type, ['ADJUSTMENT_IN', 'ADJUSTMENT_PLUS'])) {
            // Surplus Stok / Normalisasi Stok: Selalu menambah Aset Persediaan Bahan Baku / Perlengkapan (D)
            $lines[] = [
                'account_code' => $invAcc,
                'outlet_id'    => $outletId,
                'debit'        => $amount,
                'credit'       => 0,
                'memo'         => $isNormalisasi ? "Pemulihan Persediaan Normalisasi Stok {$ingName}" : "Penyesuaian Masuk / Surplus {$ingName}",
            ];
            $lines[] = [
                'account_code' => '4-41002',
                'outlet_id'    => $outletId,
                'debit'        => 0,
                'credit'       => $amount,
                'memo'         => $isNormalisasi ? "Pendapatan Selisih Normalisasi Stok {$ingName}" : "Pendapatan Selisih Surplus Stok {$ingName}",
            ];
            $desc = $isNormalisasi
                ? "Normalisasi Stok Minus #{$sm->id} - {$ingName}"
                : "Penyesuaian Masuk Stok #{$sm->id} - {$ingName}";
        } elseif ($sm->type === 'ADJUSTMENT_OUT') {
            // Defisit / Pengurangan Stok Manual: Beban Kerusakan/Defisit Stok (5-51003 / 5-51006) (D), Persediaan (K)
            $deficitAcc = $isPlk ? '5-51006' : '5-51003';
            $lines[] = [
                'account_code' => $deficitAcc,
                'outlet_id'    => $outletId,
                'debit'        => $amount,
                'credit'       => 0,
                'memo'         => "Beban Penyesuaian Keluar / Defisit Stok {$ingName}",
            ];
            $lines[] = [
                'account_code' => $invAcc,
                'outlet_id'    => $outletId,
                'debit'        => 0,
                'credit'       => $amount,
                'memo'         => "Pengurangan Persediaan {$ingName}",
            ];
            $desc = "Penyesuaian Keluar Stok #{$sm->id} - {$ingName}";
        }

        if (empty($lines)) {
            return;
        }

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $date,
            'entry_type'     => 'ADJUSTMENT',
            'reference_type' => 'STOCK_MOVEMENT',
            'reference_id'   => $sm->id,
            'description'    => $desc,
        ], $lines, $bizId);
    }

    /**
     * 11. POST PAYABLE PAYMENT (Pelunasan Hutang Supplier)
     */
    public static function postPayablePayment(PayablePayment $pp): void
    {
        $bizId = $pp->payable?->business_id ?? 1;
        $outletId = $pp->outlet_id;
        $amount = (float)$pp->amount;
        $pm = strtoupper(trim((string)$pp->payment_method));
        $srcAcc = self::getAccountCodeForPaymentMethod($pm);
        $date = $pp->payment_date ? (is_string($pp->payment_date) ? substr($pp->payment_date, 0, 10) : $pp->payment_date->format('Y-m-d')) : now()->toDateString();

        $lines = [
            ['account_code' => '2-21001', 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Pelunasan Hutang {$pp->payment_no}"],
            ['account_code' => $srcAcc,    'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Pengeluaran Kas/Bank via {$pm}"],
        ];

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $date,
            'entry_type'     => 'PAYABLE_PAYMENT',
            'reference_type' => 'PAYABLE_PAYMENT',
            'reference_id'   => $pp->id,
            'description'    => "Pelunasan Hutang Supplier #{$pp->payment_no}",
        ], $lines, $bizId);
    }

    /**
     * ⚡ SYNC ALL HISTORICAL DATA INTO JOURNAL ENTRIES
     * Resets old journal entries and posts clean, balanced double-entry journals using the new Master Accounts!
     */
    public static function syncAllHistoricalData(?int $businessId = null): array
    {
        $businesses = $businessId ? Business::where('id', $businessId)->get() : Business::all();
        $stats = [
            'initial_balances'   => 0,
            'transactions'       => 0,
            'receivable_payments'=> 0,
            'shifts'             => 0,
            'cash_transactions'  => 0,
            'expenses'           => 0,
            'transfers'          => 0,
            'wastes'             => 0,
            'opnames'            => 0,
            'purchases'          => 0,
            'adjustments'        => 0,
            'payable_payments'   => 0,
            'total_journal_entries' => 0,
        ];

        foreach ($businesses as $biz) {
            // -2. Heal corrupted urgent notes & stock balances before rebuilding journals
            self::healCorruptedUrgentNotesAndStocks($biz->id);

            // -1. Recompute Moving Average & sync cost_before / cost_after / unit_price across all stock movements
            $outlets = \App\Models\Outlet::where('business_id', $biz->id)->get();
            $ingredients = \App\Models\Ingredient::where('business_id', $biz->id)->get();
            foreach ($ingredients as $ing) {
                $visited = [];
                foreach ($outlets as $ot) {
                    $ing->recomputeMovingAverageFromHistory($ot->id, $visited);
                }
            }

            // 0. Update & reseed new master accounts in database
            Account::seedForBusiness($biz->id);

            // Petakan nomor jurnal yang sudah ada sebelumnya berdasarkan reference_type + reference_id
            // agar saat sinkronisasi ulang, nomor jurnal lama tetap dipertahankan dan tidak berubah!
            self::$preservedEntryNos = JournalEntry::where('business_id', $biz->id)
                ->whereNotNull('reference_type')
                ->whereNotNull('reference_id')
                ->pluck('entry_no', DB::raw("CONCAT(reference_type, ':', reference_id)"))
                ->all();

            // Clean up existing journal lines and entries for this business to guarantee 100% clean migration
            JournalEntry::where('business_id', $biz->id)->delete();

            // 1. Initial Balances
            self::postInitialBalance($biz);
            $stats['initial_balances']++;

            // 2. Transactions
            $txs = Transaction::where('business_id', $biz->id)->get();
            foreach ($txs as $t) {
                self::postTransaction($t);
                $stats['transactions']++;
            }

            // 3. Receivable Payments
            $rps = ReceivablePayment::whereHas('receivable', fn($q) => $q->where('business_id', $biz->id))->get();
            foreach ($rps as $rp) {
                self::postReceivablePayment($rp);
                $stats['receivable_payments']++;
            }

            // 4. Shifts
            $shifts = Shift::where('business_id', $biz->id)->get();
            foreach ($shifts as $s) {
                self::postShiftClosing($s);
                $stats['shifts']++;
            }

            // 5. Cash Transactions
            $cts = CashTransaction::where('business_id', $biz->id)->get();
            foreach ($cts as $ct) {
                self::postCashTransaction($ct);
                $stats['cash_transactions']++;
            }

            // 6. Expenses
            $oes = OperatingExpense::where('business_id', $biz->id)->get();
            foreach ($oes as $oe) {
                self::postOperatingExpense($oe);
                $stats['expenses']++;
            }

            // 7. Transfers
            $trfs = Transfer::where('business_id', $biz->id)->get();
            foreach ($trfs as $trf) {
                self::postTransfer($trf);
                $stats['transfers']++;
            }

            // 8. Waste
            $wls = WasteLog::where('business_id', $biz->id)->get();
            foreach ($wls as $wl) {
                self::postWaste($wl);
                $stats['wastes']++;
            }

            // 9. Opnames
            $ops = Opname::where('business_id', $biz->id)->get();
            foreach ($ops as $op) {
                self::postOpname($op);
                $stats['opnames']++;
            }

            // 10. Purchases & Adjustments from StockMovement
            $sms = StockMovement::where('business_id', $biz->id)
                ->whereIn('type', ['PURCHASE', 'RESTOCK', 'IN', 'ADJUSTMENT_IN', 'ADJUSTMENT_PLUS', 'ADJUSTMENT_OUT'])
                ->get();
            foreach ($sms as $sm) {
                if (in_array($sm->type, ['PURCHASE', 'RESTOCK', 'IN'])) {
                    self::postStockMovementPurchase($sm);
                    $stats['purchases']++;
                } else {
                    self::postStockMovementAdjustment($sm);
                    $stats['adjustments'] = ($stats['adjustments'] ?? 0) + 1;
                }
            }

            // 10C. Batch Preps
            $bps = BatchPrep::where('business_id', $biz->id)->get();
            foreach ($bps as $bp) {
                self::postBatchPrep($bp);
                $stats['batch_preps'] = ($stats['batch_preps'] ?? 0) + 1;
            }

            // 11. Payable Payments
            $pps = PayablePayment::whereHas('payable', fn($q) => $q->where('business_id', $biz->id))->get();
            foreach ($pps as $pp) {
                self::postPayablePayment($pp);
                $stats['payable_payments']++;
            }

            // 12. Urgent Notes Resolutions (Pelunasan Bahan Tergantung yang Disetujui)
            $uns = UrgentNote::where('business_id', $biz->id)->where('status', 'RESOLVED')->get();
            foreach ($uns as $un) {
                self::postUrgentNoteResolution($un);
                $stats['urgent_notes_resolved'] = ($stats['urgent_notes_resolved'] ?? 0) + 1;
            }
            self::$preservedEntryNos = [];
        }

        $stats['total_journal_entries'] = JournalEntry::count();
        return $stats;
    }

    /**
     * Otomatis memperbaiki dan menyinkronkan data histori yang tidak sinkron:
     * 1. Saldo berjalan (current_stock) di outlet_ingredients diselaraskan dengan mutasi riil kartu stok.
     * 2. UrgentNote milik transaksi CANCELLED / VOID dinonaktifkan (status = CANCELLED) agar tidak menggantung.
     * 3. UrgentNote yang tercatat deducted_qty = 0 padahal saat transaksi stok fisik masih ada, diselaraskan nilainya.
     */
    public static function healCorruptedUrgentNotesAndStocks(?int $businessId = null): void
    {
        $bizQuery = $businessId ? Business::where('id', $businessId) : Business::query();
        $businesses = $bizQuery->get();

        foreach ($businesses as $biz) {
            // A. Selaraskan current_stock di outlet_ingredients dengan akumulasi riil mutasi
            $outlets = Outlet::where('business_id', $biz->id)->get();
            foreach ($outlets as $outlet) {
                $outletIngredients = OutletIngredient::where('outlet_id', $outlet->id)->get();
                foreach ($outletIngredients as $oi) {
                    $init = IngredientInitialBalance::where('outlet_id', $outlet->id)
                        ->where('ingredient_id', $oi->ingredient_id)
                        ->first();
                    $stokAwal = $init ? (float)$init->stok_awal : 0.0;

                    $movements = StockMovement::where('outlet_id', $outlet->id)
                        ->where('ingredient_id', $oi->ingredient_id)
                        ->where('type', '!=', 'INITIAL')
                        ->get();

                    $netMovement = 0.0;
                    foreach ($movements as $m) {
                        $netMovement += $m->signedQty();
                    }

                    $trueStock = round($stokAwal + $netMovement, 3);
                    if (abs((float)$oi->current_stock - $trueStock) > 0.0001) {
                        $oi->update(['current_stock' => $trueStock]);
                    }
                }
            }

            // B. Batalkan UrgentNote milik transaksi yang berstatus CANCELLED, VOID, atau REFUNDED
            UrgentNote::where('business_id', $biz->id)
                ->whereHas('transaction', function ($q) {
                    $q->whereIn('status', ['CANCELLED', 'VOID', 'REFUNDED']);
                })
                ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                ->update(['status' => 'CANCELLED']);

            Transaction::where('business_id', $biz->id)
                ->whereIn('status', ['CANCELLED', 'VOID', 'REFUNDED'])
                ->where('urgent_status', '!=', 'CANCELLED')
                ->update(['urgent_status' => 'CANCELLED']);

            // C. Selaraskan deducted_qty dan pending_qty pada UrgentNote yang keliru membaca 0 saat stok sebelum transaksi masih ada
            $urgentNotes = UrgentNote::where('business_id', $biz->id)->with('transaction')->get();
            foreach ($urgentNotes as $un) {
                $t = $un->transaction;
                if (!$t) continue;
                $outletId = $un->outlet_id ?: ($t->outlet_id ?: 1);
                $ingId = $un->ingredient_id;
                if (!$ingId) continue;

                $init = IngredientInitialBalance::where('outlet_id', $outletId)
                    ->where('ingredient_id', $ingId)
                    ->first();
                $stokAwal = $init ? (float)$init->stok_awal : 0.0;

                $priorMovements = StockMovement::where('outlet_id', $outletId)
                    ->where('ingredient_id', $ingId)
                    ->where('type', '!=', 'INITIAL')
                    ->where(function ($q) use ($t) {
                        $q->where('created_at', '<', $t->created_at)
                          ->orWhere(function ($sub) use ($t) {
                              $sub->where('created_at', '=', $t->created_at)
                                  ->where('id', '<', $t->id);
                          });
                    })
                    ->get();

                $priorNet = 0.0;
                foreach ($priorMovements as $pm) {
                    $priorNet += $pm->signedQty();
                }

                $availableStockBefore = max(0.0, round($stokAwal + $priorNet, 3));
                $reqQty = (float)$un->required_qty;
                $expectedDeducted = min($reqQty, $availableStockBefore);
                $expectedPending = max(0.0, round($reqQty - $expectedDeducted, 4));

                if (abs((float)$un->deducted_qty - $expectedDeducted) > 0.001 || abs((float)$un->pending_qty - $expectedPending) > 0.001) {
                    $un->update([
                        'deducted_qty' => $expectedDeducted,
                        'pending_qty'  => $expectedPending,
                    ]);
                }
            }
        }
    }
}
