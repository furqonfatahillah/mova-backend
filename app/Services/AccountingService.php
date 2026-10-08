<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Business;
use App\Models\CashTransaction;
use App\Models\Ingredient;
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
use App\Models\WasteLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountingService
{
    /**
     * Map payment method string to Account Code
     */
    public static function getAccountCodeForPaymentMethod(?string $method): string
    {
        $m = strtoupper(trim((string)$method));
        if ($m === 'CASH' || $m === 'TUNAI' || $m === 'PETTY_CASH' || $m === 'KAS' || $m === 'KASIR') {
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
        if (str_contains($m, 'TRANSFER') || str_contains($m, 'BANK') || str_contains($m, 'DEBIT') || str_contains($m, 'EDC') || str_contains($m, 'KARTU') || str_contains($m, 'CREDIT') || str_contains($m, 'BCA') || str_contains($m, 'MANDIRI') || str_contains($m, 'BRI') || str_contains($m, 'BNI')) {
            return '1-11003'; // BANK
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

        // Delete previous entry with the same reference if updating
        if (!empty($data['reference_type']) && !empty($data['reference_id'])) {
            $existing = JournalEntry::where('business_id', $bizId)
                ->where('reference_type', $data['reference_type'])
                ->where('reference_id', $data['reference_id'])
                ->first();
            if ($existing) {
                $existing->delete();
            }
        }

        $entryDate = $data['entry_date'] ?? now()->toDateString();
        $entryNo = $data['entry_no'] ?? JournalEntry::generateEntryNo($bizId, $entryDate);

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
            $isMainOutlet = (bool)$outlet->is_main || ((int)$outlet->id === 1);
            $stockInitDate = null;

            foreach ($ings as $ing) {
                $isPlk = self::isPerlengkapan($ing);

                $oi = $ing->outletIngredients->firstWhere('outlet_id', $outlet->id);
                $stokAwal = $oi ? (float)$oi->stok_awal : ($isMainOutlet ? (float)$ing->stok_awal : 0.0);
                $costUnit = $ing->costPerPakaiForOutlet($outlet->id);
                $saldoAwalNom = ($oi && (float)$oi->saldo_awal_nominal > 0) ? (float)$oi->saldo_awal_nominal : round($stokAwal * $costUnit, 2);

                if ($saldoAwalNom > 0) {
                    if ($isPlk) {
                        $totalPlkAwal += $saldoAwalNom;
                    } else {
                        $totalBahanAwal += $saldoAwalNom;
                    }
                    if (!$stockInitDate) {
                        $stockInitDate = $oi?->tanggal_saldo_awal ?: $ing->tanggal_saldo_awal;
                    }
                }
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
     * 2. POST POS TRANSACTION (Penjualan, Piutang Kasbon, Diskon, & HPP Resep)
     */
    public static function postTransaction(Transaction $t): void
    {
        if (!in_array($t->status, ['PAID', 'HOLD', 'SPLIT_CLOSED'])) {
            // Delete journal entry if cancelled or voided
            JournalEntry::where('business_id', $t->business_id ?? 1)
                ->where('reference_type', 'TRANSACTION')
                ->where('reference_id', $t->id)
                ->delete();
            return;
        }

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
        // If status is HOLD (Open Bill) or payment method is KASBON/PIUTANG, it goes to Piutang Usaha (1-12001)
        if ($t->status === 'HOLD' || $pm === 'KASBON' || $pm === 'PIUTANG') {
            $lines[] = [
                'account_code' => '1-12001', // PIUTANG USAHA
                'outlet_id'    => $outletId,
                'debit'        => $totalNet,
                'credit'       => 0,
                'memo'         => ($t->status === 'HOLD') ? "Piutang Open Bill {$t->order_number}" : "Piutang Kasbon {$t->order_number}",
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

        // B. COGS & Inventory Usage Side (HPP Resep)
        $cogsBahan = 0.0;
        $cogsPlk = 0.0;

        $menu = $t->menu;
        if ($menu) {
            if ($menu->item_type === 'DIRECT' || (!$menu->activeRecipe($t->date) && (float)$menu->cost_price > 0)) {
                $cogsBahan += (float)$menu->cost_price * (int)$t->qty;
            } else {
                $recipe = $menu->recipes->firstWhere('version', $t->recipe_version) ?: $menu->recipes->first();
                if ($recipe) {
                    foreach ($recipe->items as $it) {
                        $ing = $it->ingredient;
                        if (!$ing) continue;
                        $isPlk = self::isPerlengkapan($ing);
                        $unitCost = $ing->costPerPakaiForOutlet($outletId);
                        $itemCost = (float)$it->qty * (int)$t->qty * $unitCost;

                        if ($isPlk) {
                            $cogsPlk += $itemCost;
                        } else {
                            $cogsBahan += $itemCost;
                        }
                    }
                }
            }
        }

        $cogsBahan = round($cogsBahan, 2);
        $cogsPlk = round($cogsPlk, 2);

        if ($cogsBahan > 0) {
            $lines[] = ['account_code' => '5-51001', 'outlet_id' => $outletId, 'debit' => $cogsBahan, 'credit' => 0, 'memo' => 'Beban Bahan Baku (HPP)'];
            $lines[] = ['account_code' => '1-13001', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $cogsBahan, 'memo' => 'Pengurangan Persediaan Bahan'];
        }
        if ($cogsPlk > 0) {
            $lines[] = ['account_code' => '5-51002', 'outlet_id' => $outletId, 'debit' => $cogsPlk, 'credit' => 0, 'memo' => 'Beban Kemasan & Packaging (HPP)'];
            $lines[] = ['account_code' => '1-13002', 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $cogsPlk, 'memo' => 'Pengurangan Persediaan Perlengkapan'];
        }

        self::recordEntry([
            'business_id'    => $bizId,
            'outlet_id'      => $outletId,
            'entry_date'     => $t->date,
            'entry_type'     => 'SALES',
            'reference_type' => 'TRANSACTION',
            'description'    => ($t->status === 'HOLD')
                ? "Penjualan POS Open Bill #{$t->order_number} (Piutang Meja)"
                : "Penjualan POS #{$t->order_number} ({$pm})",
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
     * 4. POST SHIFT CLOSING (Selisih Lebih -> Pendapatan Lain-Lain POS, Selisih Kurang -> Biaya Lain-Lain POS)
     */
    public static function postShiftClosing(Shift $shift): void
    {
        $bizId = $shift->business_id ?? 1;

        if ($shift->status !== 'CLOSED' || $shift->closing_cash === null) {
            self::deleteReferenceEntry('SHIFT_CLOSING', $shift->id, $bizId);
            return;
        }

        $diff = $shift->cash_difference !== null
            ? round((float)$shift->cash_difference, 2)
            : round((float)$shift->closing_cash - (float)$shift->system_cash, 2);

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
            // Saldo Awal Modal Kasir Pertama Kali: Kasir (1-11002) Debit, Modal Pemilik Disetor (3-31001) Kredit (bukan Kas Besar)
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
            // Setoran Kas Aplikasi (QRIS) ke Rekening Bank: Bank (1-11003) Debit, Kas Aplikasi (1-11004) Kredit
            $destCode = ($acc === 'KAS_BESAR' ? '1-11001' : '1-11003');
            $lines[] = ['account_code' => $destCode,   'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Penerimaan Setoran Kas Aplikasi ke Bank {$ct->account}"];
            $lines[] = ['account_code' => '1-11004',   'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Penyetoran Kas Aplikasi (QRIS) ke Bank {$ct->account}"];
        } elseif (str_contains($cat, 'KARYAWAN') || str_contains($cat, 'KASBON_STAFF')) {
            // Kasbon Karyawan (dialihkan ke 1-12001 Piutang Usaha)
            $srcCode = ($acc === 'BANK_MAIN' || str_contains($acc, 'BANK')) ? '1-11003' : ($acc === 'KAS_BESAR' ? '1-11001' : '1-11002');
            $lines[] = ['account_code' => '1-12001', 'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Piutang Kasbon Karyawan'];
            $lines[] = ['account_code' => $srcCode, 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Pengeluaran Kas Kasbon Karyawan'];
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

        // Map category to OPEX account code
        $expCode = '6-61009'; // default BIAYA OPERASIONAL LAINNYA
        if (str_contains($cat, 'GAJI') || str_contains($cat, 'SALARY') || str_contains($cat, 'UPAH') || str_contains($cat, 'KARYAWAN')) {
            $expCode = '6-61001'; // BIAYA GAJI KARYAWAN
        } elseif (str_contains($cat, 'LISTRIK') || str_contains($cat, 'PLN')) {
            $expCode = '6-61002'; // BIAYA LISTRIK
        } elseif (str_contains($cat, 'AIR') || str_contains($cat, 'PDAM')) {
            $expCode = '6-61003'; // BIAYA AIR
        } elseif (str_contains($cat, 'GAS') || str_contains($cat, 'ELPIJI') || str_contains($cat, 'LPG')) {
            $expCode = '6-61004'; // BIAYA GAS
        } elseif (str_contains($cat, 'PEMELIHARAAN') || str_contains($cat, 'MAINTENANCE') || str_contains($cat, 'SERVIS') || str_contains($cat, 'PERBAIKAN') || str_contains($cat, 'RENOVASI')) {
            $expCode = '6-61005'; // BIAYA PEMELIHARAAN ASET
        } elseif (str_contains($cat, 'MARKETING') || str_contains($cat, 'PROMOSI') || str_contains($cat, 'IKLAN') || str_contains($cat, 'ADS')) {
            $expCode = '6-61006'; // BIAYA MARKETING
        } elseif (str_contains($cat, 'LOGISTIK') || str_contains($cat, 'TRANSPORT') || str_contains($cat, 'ONGKIR') || str_contains($cat, 'BENSIN') || str_contains($cat, 'PENGIRIMAN')) {
            $expCode = '6-61007'; // BIAYA LOGISTIK & TRANSPORTASI
        } elseif ((str_contains($cat, 'SELISIH') && str_contains($cat, 'KAS')) || str_contains($cat, 'LAIN')) {
            $expCode = '6-61008'; // BIAYA LAIN-LAIN
        } else {
            $expCode = '6-61009'; // BIAYA OPERASIONAL LAINNYA
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
        if (!in_array($trf->status, ['SENT', 'RECEIVED', 'COMPLETED', 'APPROVED'])) {
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
            $itemVal = (float)$it->qty * $costUnit;

            if ($isPlk) {
                $totalValPlk += $itemVal;
            } else {
                $totalValBahan += $itemVal;
            }
        }

        $totalVal = round($totalValBahan + $totalValPlk, 2);
        if ($totalVal <= 0) return;

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

        if ($ing) {
            // Direct ingredient waste
            $isPlk = self::isPerlengkapan($ing);
            $invAcc = $isPlk ? '1-13002' : '1-13001';
            $wasteExpAcc = $isPlk ? '5-51006' : '5-51003';
            $memoType = $isPlk ? 'Perlengkapan' : 'Bahan Baku';

            $lines = [
                ['account_code' => $wasteExpAcc, 'outlet_id' => $outletId, 'debit' => $lossCost, 'credit' => 0, 'memo' => "Beban Kerugian Waste {$memoType} ({$ing->name}): {$memoReason}"],
                ['account_code' => $invAcc,      'outlet_id' => $outletId, 'debit' => 0, 'credit' => $lossCost, 'memo' => "Pengurangan Persediaan {$memoType} ({$ing->name})"],
            ];
        } elseif ($menu) {
            // Menu / Recipe waste: check if recipe has both Bahan Baku and Perlengkapan
            $wasteBahan = 0.0;
            $wastePlk = 0.0;
            $recipe = $menu->recipes?->first();

            if ($recipe && $recipe->items && $recipe->items->count() > 0) {
                $recipeBahanCost = 0.0;
                $recipePlkCost = 0.0;

                foreach ($recipe->items as $it) {
                    $itemIng = $it->ingredient;
                    if (!$itemIng) continue;
                    $isPlk = self::isPerlengkapan($itemIng);
                    $unitCost = method_exists($itemIng, 'costPerPakaiForOutlet') 
                        ? $itemIng->costPerPakaiForOutlet($outletId) 
                        : (float)($itemIng->harga / max(1, $itemIng->konversi));
                    $itemCost = (float)$it->qty * $unitCost;

                    if ($isPlk) {
                        $recipePlkCost += $itemCost;
                    } else {
                        $recipeBahanCost += $itemCost;
                    }
                }

                $totalRecipeCost = $recipeBahanCost + $recipePlkCost;
                if ($totalRecipeCost > 0) {
                    $ratioBahan = $recipeBahanCost / $totalRecipeCost;
                    $ratioPlk = $recipePlkCost / $totalRecipeCost;
                    $wasteBahan = round($lossCost * $ratioBahan, 2);
                    $wastePlk = round($lossCost - $wasteBahan, 2);
                } else {
                    $wasteBahan = $lossCost;
                }
            } else {
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
        $code = $ing ? strtoupper((string)$ing->code) : '';
        $cat = $ing ? strtolower((string)$ing->category) : '';
        $isPlk = str_starts_with($code, 'PLK-') || str_starts_with($code, 'PKG-') || str_contains($cat, 'cup') || str_contains($cat, 'sedotan') || str_contains($cat, 'kemasan') || str_contains($cat, 'packaging');
        $invAcc = $isPlk ? '1-13002' : '1-13001';
        $deficitAcc = $isPlk ? '5-51005' : '5-51004';

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
        if (!in_array($sm->type, ['PURCHASE', 'RESTOCK', 'IN']) || (float)$sm->total_price <= 0) {
            return;
        }

        $bizId = $sm->business_id ?? 1;
        $outletId = $sm->outlet_id;
        $amount = (float)$sm->total_price;
        $date = $sm->date ?: now()->toDateString();
        $ing = $sm->ingredient;
        $code = $ing ? strtoupper((string)$ing->code) : '';
        $cat = $ing ? strtolower((string)$ing->category) : '';
        $isPlk = str_starts_with($code, 'PLK-') || str_starts_with($code, 'PKG-') || str_contains($cat, 'cup') || str_contains($cat, 'sedotan') || str_contains($cat, 'kemasan') || str_contains($cat, 'packaging');
        $invAcc = $isPlk ? '1-13002' : '1-13001';

        $payType = strtoupper(trim((string)$sm->payment_type));
        $credAcc = ($payType === 'CREDIT' || $payType === 'HUTANG' || $sm->payable_id) ? '2-21001' : '1-11001';

        $lines = [
            ['account_code' => $invAcc,  'outlet_id' => $outletId, 'debit' => $amount, 'credit' => 0, 'memo' => "Pembelian {$ing?->name}"],
            ['account_code' => $credAcc, 'outlet_id' => $outletId, 'debit' => 0, 'credit' => $amount, 'memo' => "Pembayaran/Hutang Pembelian {$sm->note}"],
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
            'payable_payments'   => 0,
            'total_journal_entries' => 0,
        ];

        foreach ($businesses as $biz) {
            // 0. Update & reseed new master accounts in database
            Account::seedForBusiness($biz->id);

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

            // 10. Purchases from StockMovement
            $sms = StockMovement::where('business_id', $biz->id)
                ->whereIn('type', ['PURCHASE', 'RESTOCK', 'IN'])
                ->get();
            foreach ($sms as $sm) {
                self::postStockMovementPurchase($sm);
                $stats['purchases']++;
            }

            // 11. Payable Payments
            $pps = PayablePayment::whereHas('payable', fn($q) => $q->where('business_id', $biz->id))->get();
            foreach ($pps as $pp) {
                self::postPayablePayment($pp);
                $stats['payable_payments']++;
            }
        }

        $stats['total_journal_entries'] = JournalEntry::count();
        return $stats;
    }
}
