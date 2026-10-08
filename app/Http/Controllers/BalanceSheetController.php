<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Outlet;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BalanceSheetController extends Controller
{
    private function formatIndonesianDate(string $dateStr): string
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $ts = strtotime($dateStr);
        $d = date('d', $ts);
        $m = (int)date('m', $ts);
        $y = date('Y', $ts);

        return "{$d} " . ($months[$m] ?? date('F', $ts)) . " {$y}";
    }

    /**
     * Get Comprehensive Double-Entry Balance Sheet Report (Laporan Neraca Keuangan Buku Besar)
     * Langsung membaca dari tabel journal_entries & journal_lines sehingga Aktiva = Passiva (Selisih Rp 0,00).
     */
    public function index(Request $request)
    {
        $from = $request->input('from', date('Y-m-01'));
        $to   = $request->input('to', date('Y-m-d'));

        $user = $request->user();
        $businessId = $user ? $user->business_id : null;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        $outletName = 'Semua Cabang (Konsolidasi)';
        if ($outletId) {
            $outletObj = Outlet::find($outletId);
            if ($outletObj) {
                $outletName = $outletObj->name;
            }
        }

        // Auto-seed COA & auto-sync if journal lines/entries are empty
        if ($businessId) {
            Account::seedForBusiness($businessId);
            $hasJournalLines = JournalLine::whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId))->exists();
            if (!$hasJournalLines) {
                AccountingService::syncAllHistoricalData($businessId);
            }
        }

        // =========================================================================
        // 1. QUERY GENERAL LEDGER BALANCES UP TO $to
        // =========================================================================
        $baseLinesQuery = JournalLine::where('entry_date', '<=', $to);
        if ($outletId) {
            $baseLinesQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $baseLinesQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }

        $balances = (clone $baseLinesQuery)
            ->select('account_code', DB::raw('SUM(debit) as total_debit'), DB::raw('SUM(credit) as total_credit'))
            ->groupBy('account_code')
            ->get();

        // Fetch all active Head accounts of this business from the database (100% DYNAMIC)
        $allAccounts = Account::when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->whereNull('parent_code')
            ->where('is_active', true)
            ->orderBy('code', 'asc')
            ->get();

        $getAccountBalance = function (Account $acc) use ($balances): float {
            $matchingRows = $balances->filter(function ($row) use ($acc) {
                return $row->account_code === $acc->code || str_starts_with($row->account_code, "{$acc->code}.");
            });

            if ($matchingRows->isEmpty()) return 0.0;
            $deb = (float)$matchingRows->sum('total_debit');
            $crd = (float)$matchingRows->sum('total_credit');

            return $acc->normal_balance === 'DEBIT'
                ? round($deb - $crd, 2)
                : round($crd - $deb, 2);
        };

        // =========================================================================
        // 2. ASET LANCAR & ASET TETAP (Dinamis dari Database)
        // =========================================================================
        $currentAssetAccounts = [];
        $fixedAssetAccounts = [];
        $totalCashAndBank = 0.0;
        $totalReceivables = 0.0;
        $totalInventory   = 0.0;

        $obsoleteCodes = ['1-12003', '1-12004', '1-12005', '1-12006', '1-12007'];

        foreach ($allAccounts->where('type', 'ASSET') as $acc) {
            if (in_array($acc->code, $obsoleteCodes)) {
                continue;
            }

            $amt = $getAccountBalance($acc);
            $cat = strtoupper((string)($acc->category ?? ''));
            $item = [
                'code'   => $acc->code,
                'name'   => $acc->name,
                'amount' => $amt,
            ];
            if (str_starts_with($acc->code, '1-2') || in_array($cat, ['FIXED_ASSET', 'EQUIPMENT', 'BUILDING', 'VEHICLE', 'ASET TETAP'])) {
                $fixedAssetAccounts[] = $item;
            } else {
                $currentAssetAccounts[] = $item;
                if (in_array($cat, ['CASH', 'BANK']) || str_starts_with($acc->code, '1-11')) {
                    $totalCashAndBank += $amt;
                } elseif (in_array($cat, ['RECEIVABLE', 'AR']) || str_starts_with($acc->code, '1-12')) {
                    $totalReceivables += $amt;
                } elseif (in_array($cat, ['INVENTORY']) || str_starts_with($acc->code, '1-13')) {
                    $totalInventory += $amt;
                }
            }
        }

        // Urutkan daftar aset lancar berdasarkan kode akun agar rapi dan terstruktur
        usort($currentAssetAccounts, fn($a, $b) => strcmp($a['code'], $b['code']));

        $jumlahAsetLancar = round(array_sum(array_column($currentAssetAccounts, 'amount')), 2);
        $jumlahAsetTetap  = round(array_sum(array_column($fixedAssetAccounts, 'amount')), 2);
        $jumlahAset       = round($jumlahAsetLancar + $jumlahAsetTetap, 2);

        // =========================================================================
        // 3. LIABILITAS / KEWAJIBAN (Dinamis dari Database)
        // =========================================================================
        $liabilityAccounts = [];
        foreach ($allAccounts->where('type', 'LIABILITY') as $acc) {
            $amt = $getAccountBalance($acc);
            $liabilityAccounts[] = [
                'code'   => $acc->code,
                'name'   => $acc->name,
                'amount' => $amt,
            ];
        }
        $jumlahHutang = round(array_sum(array_column($liabilityAccounts, 'amount')), 2);

        // =========================================================================
        // 4. EKUITAS & MODAL (Dinamis dari Database + P&L Nominal)
        // =========================================================================
        // A. Saldo Laba Ditahan (Akumulasi Laba Bersih Masa Lalu sebelum tanggal $from)
        $priorNominalQuery = JournalLine::where('entry_date', '<', $from)
            ->where(function ($q) {
                $q->where('account_code', 'like', '4-%')
                  ->orWhere('account_code', 'like', '5-%')
                  ->orWhere('account_code', 'like', '6-%');
            });
        if ($outletId) {
            $priorNominalQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $priorNominalQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }
        $priorTotals = $priorNominalQuery->select(DB::raw('SUM(credit) as total_credit'), DB::raw('SUM(debit) as total_debit'))->first();
        $saldoLabaDitahan = round((float)($priorTotals?->total_credit ?? 0) - (float)($priorTotals?->total_debit ?? 0), 2);

        // B. Laba Tahun Ini / Periode Berjalan ($from s/d $to)
        $currentNominalQuery = JournalLine::whereBetween('entry_date', [$from, $to])
            ->where(function ($q) {
                $q->where('account_code', 'like', '4-%')
                  ->orWhere('account_code', 'like', '5-%')
                  ->orWhere('account_code', 'like', '6-%');
            });
        if ($outletId) {
            $currentNominalQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $currentNominalQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }
        $currentTotals = $currentNominalQuery->select(DB::raw('SUM(credit) as total_credit'), DB::raw('SUM(debit) as total_debit'))->first();
        $labaTahunIni = round((float)($currentTotals?->total_credit ?? 0) - (float)($currentTotals?->total_debit ?? 0), 2);

        $equityAccounts = [];
        foreach ($allAccounts->where('type', 'EQUITY') as $acc) {
            if ($acc->code === '3-31003') {
                continue;
            }
            $amt = $getAccountBalance($acc);
            if ($acc->code === '3-31002' || $acc->normal_balance === 'DEBIT') {
                $amt = round(-abs($amt), 2);
            }
            $equityAccounts[] = [
                'code'   => $acc->code,
                'name'   => $acc->name,
                'amount' => $amt,
            ];
        }

        if (abs($saldoLabaDitahan) >= 0.01) {
            $equityAccounts[] = [
                'code'   => '3-31003',
                'name'   => 'SALDO LABA DITAHAN (PERIODE LALU)',
                'amount' => $saldoLabaDitahan,
            ];
        }

        $equityAccounts[] = [
            'code'   => '3-31003',
            'name'   => 'LABA PERIODE BERJALAN',
            'amount' => $labaTahunIni,
        ];

        $jumlahModal = round(array_sum(array_column($equityAccounts, 'amount')), 2);
        $jumlahKewajibanDanModal = round($jumlahHutang + $jumlahModal, 2);

        // Selisih Neraca = Aset - (Liabilitas + Modal)
        $difference = round($jumlahAset - $jumlahKewajibanDanModal, 2);
        $isBalanced = abs($difference) < 0.05;

        return response()->json([
            'period' => [
                'from'           => $from,
                'to'             => $to,
                'from_formatted' => $this->formatIndonesianDate($from),
                'to_formatted'   => $this->formatIndonesianDate($to),
                'outlet_id'      => $outletId,
                'outlet_name'    => $outletName,
            ],
            'current_assets' => [
                'label'    => 'Aset Lancar',
                'accounts' => $currentAssetAccounts,
                'subtotal' => $jumlahAsetLancar,
            ],
            'fixed_assets' => [
                'label'    => 'Aset Tetap',
                'accounts' => $fixedAssetAccounts,
                'subtotal' => $jumlahAsetTetap,
            ],
            'total_assets' => [
                'label'  => 'Jumlah Aset',
                'amount' => $jumlahAset,
            ],
            'liabilities' => [
                'label'    => 'Liabilitas',
                'accounts' => $liabilityAccounts,
                'subtotal' => $jumlahHutang,
            ],
            'equity' => [
                'label'    => 'Modal',
                'accounts' => $equityAccounts,
                'subtotal' => $jumlahModal,
            ],
            'total_liabilities_and_equity' => [
                'label'  => 'Jumlah Kewajiban dan Modal',
                'amount' => $jumlahKewajibanDanModal,
            ],
            'difference'  => $difference,
            'is_balanced' => $isBalanced,
            'summary'     => [
                'total_cash_and_bank' => round($totalCashAndBank, 2),
                'total_receivables'   => round($totalReceivables, 2),
                'total_inventory'     => round($totalInventory, 2),
                'total_liabilities'   => $jumlahHutang,
                'total_equity'        => $jumlahModal,
                'net_profit'          => $labaTahunIni,
            ],
        ]);
    }

    /**
     * Get Drill-Down Detailed Audit Trail for a Specific Balance Sheet Account from General Ledger
     */
    public function detail(Request $request)
    {
        $accountCode = trim((string)$request->input('account_code', ''));
        $accountName = trim((string)$request->input('account_name', ''));
        $from = $request->input('from', date('Y-m-01'));
        $to   = $request->input('to', date('Y-m-d'));

        $user = $request->user();
        $businessId = $user ? $user->business_id : null;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        $outletName = 'Semua Cabang (Konsolidasi)';
        if ($outletId) {
            $outletObj = Outlet::find($outletId);
            if ($outletObj) {
                $outletName = $outletObj->name;
            }
        }

        // Fetch Account details dynamically from database
        $accountObj = Account::when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->where('code', $accountCode)
            ->first() ?? Account::where('code', $accountCode)->first();

        $accName = (!empty($accountName) && !str_starts_with($accountName, 'Akun [')) ? $accountName : ($accountObj?->name ?: "Akun [{$accountCode}]");
        $accType = $accountObj?->type ?: (str_starts_with($accountCode, '1-') ? 'ASSET' : (str_starts_with($accountCode, '2-') ? 'LIABILITY' : (str_starts_with($accountCode, '3-') ? 'EQUITY' : (str_starts_with($accountCode, '4-') ? 'REVENUE' : 'EXPENSE'))));
        $accCategory = $accountObj?->category ?: (str_starts_with($accountCode, '1-') ? 'Aset Lancar' : (str_starts_with($accountCode, '2-') ? 'Liabilitas' : 'Modal'));
        $accDesc = $accountObj?->description ?: "Buku besar jurnal umum untuk akun {$accName} ({$accountCode}).";
        $normalBalance = $accountObj?->normal_balance ?: (str_starts_with($accountCode, '1-') || str_starts_with($accountCode, '5-') || str_starts_with($accountCode, '6-') || $accountCode === '3-31002' ? 'DEBIT' : 'CREDIT');

        // Determine smart navigation link based on account category/type
        $actionLink = '/neraca';
        $actionLabel = 'Kembali ke Neraca';
        $catUpper = strtoupper((string)$accCategory);
        if ($accountCode === '1-11004' || str_contains(strtoupper($accName), 'KAS APLIKASI')) {
            $actionLink = '/kas-aplikasi';
            $actionLabel = 'Buka Buku Kas Aplikasi & Setoran Bank';
        } elseif (in_array($catUpper, ['CASH', 'BANK']) || str_starts_with($accountCode, '1-11')) {
            $actionLink = '/cash-flow';
            $actionLabel = 'Buka Buku Kas & Arus Kas';
        } elseif (in_array($catUpper, ['RECEIVABLE', 'AR']) || str_starts_with($accountCode, '1-12')) {
            $actionLink = '/receivables';
            $actionLabel = 'Buka Buku Piutang Kasbon';
        } elseif (in_array($catUpper, ['INVENTORY']) || str_starts_with($accountCode, '1-13')) {
            $actionLink = '/kartu-stok';
            $actionLabel = 'Buka Kartu Stok Persediaan';
        } elseif ($accType === 'LIABILITY' || str_starts_with($accountCode, '2-')) {
            $actionLink = '/payables';
            $actionLabel = 'Buka Buku Hutang Supplier';
        } elseif ($accType === 'EQUITY' || str_starts_with($accountCode, '3-')) {
            if ($accountCode === '3-31003') {
                $actionLink = '/profit-loss';
                $actionLabel = 'Buka Laporan Laba Rugi';
            } else {
                $actionLink = '/cash-flow';
                $actionLabel = 'Buka Riwayat Mutasi Modal';
            }
        } elseif ($accType === 'REVENUE' || $accType === 'EXPENSE' || in_array(substr($accountCode, 0, 1), ['4', '5', '6'])) {
            $actionLink = '/profit-loss';
            $actionLabel = 'Buka Laporan Laba Rugi';
        }

        $formulaText = $normalBalance === 'DEBIT'
            ? 'Total Debit Terbukukan - Total Kredit Terbukukan'
            : 'Total Kredit Terbukukan - Total Debit Terbukukan';

        $isRetainedEarnings = $accountCode === '3-31003' && (str_contains(strtoupper((string)$accName), 'DITAHAN') || str_contains(strtoupper((string)$accName), 'LALU'));
        $isCurrentProfit = $accountCode === '3-31003' && !$isRetainedEarnings;

        // Query lines for this account from General Ledger
        $linesQuery = JournalLine::with(['journalEntry', 'outlet', 'account']);

        if ($isRetainedEarnings) {
            $linesQuery->where('entry_date', '<', $from)
                ->where(function ($q) {
                    $q->where('account_code', 'like', '4-%')
                      ->orWhere('account_code', 'like', '5-%')
                      ->orWhere('account_code', 'like', '6-%');
                });
        } elseif ($isCurrentProfit) {
            $linesQuery->whereBetween('entry_date', [$from, $to])
                ->where(function ($q) {
                    $q->where('account_code', 'like', '4-%')
                      ->orWhere('account_code', 'like', '5-%')
                      ->orWhere('account_code', 'like', '6-%');
                });
        } else {
            $linesQuery->where('entry_date', '<=', $to)
                ->where(function ($q) use ($accountCode) {
                    $q->where('account_code', $accountCode)
                      ->orWhere('account_code', 'like', "{$accountCode}.%");
                });
        }

        if ($outletId) {
            $linesQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $linesQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }

        $lines = $linesQuery->orderBy('entry_date', 'desc')->orderBy('id', 'desc')->limit(300)->get();

        $totalDebit  = (float)$lines->sum('debit');
        $totalCredit = (float)$lines->sum('credit');

        $isDebitNormal = $normalBalance === 'DEBIT';
        $currentBalance = ($isRetainedEarnings || $isCurrentProfit)
            ? round($totalCredit - $totalDebit, 2)
            : ($isDebitNormal ? round($totalDebit - $totalCredit, 2) : round($totalCredit - $totalDebit, 2));

        // Outlets breakdown for consolidated drill-down (hanya menampilkan cabang terdaftar)
        $outlets = Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))->get();
        $mainOutlet = $outlets->firstWhere('is_main', true) ?? $outlets->first();
        $mainOutletId = $mainOutlet?->id;

        // Auto-backfill null outlet_id to main outlet id if any
        if ($mainOutletId && $businessId) {
            try {
                JournalLine::whereNull('outlet_id')
                    ->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId))
                    ->update(['outlet_id' => $mainOutletId]);
                JournalEntry::where('business_id', $businessId)
                    ->whereNull('outlet_id')
                    ->update(['outlet_id' => $mainOutletId]);
            } catch (\Throwable $e) {}
        }

        $outletsBreakdown = [];

        $outletLinesQuery = JournalLine::query();
        if ($isRetainedEarnings) {
            $outletLinesQuery->where('entry_date', '<', $from)
                ->where(function ($q) {
                    $q->where('account_code', 'like', '4-%')
                      ->orWhere('account_code', 'like', '5-%')
                      ->orWhere('account_code', 'like', '6-%');
                });
        } elseif ($isCurrentProfit) {
            $outletLinesQuery->whereBetween('entry_date', [$from, $to])
                ->where(function ($q) {
                    $q->where('account_code', 'like', '4-%')
                      ->orWhere('account_code', 'like', '5-%')
                      ->orWhere('account_code', 'like', '6-%');
                });
        } else {
            $outletLinesQuery->where('entry_date', '<=', $to)
                ->where(function ($q) use ($accountCode) {
                    $q->where('account_code', $accountCode)
                      ->orWhere('account_code', 'like', "{$accountCode}.%");
                });
        }

        if ($businessId) {
            $outletLinesQuery->whereHas('journalEntry', fn($q) => $q->where('business_id', $businessId));
        }

        $outletSums = (clone $outletLinesQuery)
            ->select('outlet_id', DB::raw('SUM(debit) as total_debit'), DB::raw('SUM(credit) as total_credit'), DB::raw('COUNT(*) as tx_count'))
            ->groupBy('outlet_id')
            ->get()
            ->keyBy('outlet_id');

        $nullRow = $outletSums->get(null) ?? $outletSums->get(0);

        foreach ($outlets as $out) {
            $row = $outletSums->get($out->id);
            $deb = (float)($row?->total_debit ?? 0);
            $crd = (float)($row?->total_credit ?? 0);
            $txCount = (int)($row?->tx_count ?? 0);

            // Mutasi tanpa outlet_id (misal saldo awal/modal umum) otomatis digabung ke Cabang Utama
            if ($out->id === $mainOutletId && $nullRow) {
                $deb += (float)$nullRow->total_debit;
                $crd += (float)$nullRow->total_credit;
                $txCount += (int)$nullRow->tx_count;
            }

            if ($isRetainedEarnings || $isCurrentProfit) {
                $bal = round($crd - $deb, 2);
            } else {
                $bal = $isDebitNormal ? round($deb - $crd, 2) : round($crd - $deb, 2);
            }

            $bizId = $businessId ?: ($out->business_id ?? 1);
            $subCode = Account::getSubAccountCode($accountCode, $bizId, $out->id);

            $outletsBreakdown[] = [
                'outlet_id'    => $out->id,
                'sub_code'     => $subCode,
                'outlet_name'  => $out->name,
                'address'      => $out->address,
                'total_debit'  => $deb,
                'total_credit' => $crd,
                'balance'      => $bal,
                'tx_count'     => $txCount,
            ];
        }

        $items = $lines->map(function ($l) use ($isDebitNormal, $mainOutlet) {
            $je = $l->journalEntry;
            $amt = $l->debit > 0 ? (float)$l->debit : (float)$l->credit;
            $dir = $l->debit > 0 ? ($isDebitNormal ? 'MASUK / DEBIT' : 'DEBIT') : ($isDebitNormal ? 'KELUAR / KREDIT' : 'KREDIT');

            $dateStr = is_string($l->entry_date) ? substr($l->entry_date, 0, 10) : ($l->entry_date ? $l->entry_date->format('Y-m-d') : '-');

            $rawCat = $je?->entry_type ?: ($je?->reference_type ?: 'JURNAL');
            $stdCat = match ($rawCat) {
                'SETORAN_KASIR', 'CASH_DEPOSIT'     => 'SETORAN_KASIR',
                'TARIK_MODAL_KASIR'                 => 'TARIK_MODAL_KASIR',
                'SHIFT_CLOSING'                     => 'SHIFT_CLOSING',
                'RECEIVABLE_PAYMENT'                => 'RECEIVABLE_PAYMENT',
                'SALE', 'SALES', 'INVOICE', 'POS_SALE' => 'PENJUALAN_KASIR',
                'OPEX', 'OPERATING_EXPENSE'         => 'BIAYA_OPERASIONAL',
                'CAPITAL_INJECTION'                 => 'MODAL_DISETOR',
                'OWNER_WITHDRAWAL', 'PRIVE'         => 'PRIVE_PEMILIK',
                'INITIAL_BALANCE', 'INITIAL_BALANCE_CASH' => 'SALDO_AWAL',
                default                             => $rawCat,
            };

            $desc = $l->memo ?: ($je?->description ?: '-');

            return [
                'id'          => $l->id,
                'journal_id'  => $l->journal_entry_id,
                'outlet_id'   => $l->outlet_id ?: $mainOutlet?->id,
                'date'        => $dateStr,
                'ref_no'      => $je?->entry_no ?: "JE-{$l->journal_entry_id}",
                'category'    => $stdCat,
                'raw_category'=> $rawCat,
                'description' => $desc,
                'direction'   => $dir,
                'debit'       => (float)$l->debit,
                'credit'      => (float)$l->credit,
                'amount'      => $amt,
                'user_name'   => $l->outlet?->name ?: ($mainOutlet?->name ?: 'Cabang Utama'),
            ];
        });

        $components = [
            ['label' => 'Total Debit Terbukukan', 'value' => $totalDebit, 'type' => 'success', 'prefix' => '(D)'],
            ['label' => 'Total Kredit Terbukukan', 'value' => $totalCredit, 'type' => 'danger', 'prefix' => '(K)'],
            ['label' => 'Saldo Buku Besar Akun', 'value' => $currentBalance, 'type' => 'primary', 'prefix' => '(=)'],
        ];

        return response()->json([
            'account_code'      => $accountCode,
            'account_name'      => $accName,
            'account_type'      => $accType,
            'category_label'    => $accCategory,
            'amount'            => $currentBalance,
            'explanation'       => $accDesc,
            'formula'           => $formulaText,
            'components'        => $components,
            'is_consolidated'   => ($outletId === null),
            'outlets'           => $outletsBreakdown,
            'outlets_breakdown' => $outletsBreakdown,
            'columns'           => [
                ['key' => 'date', 'label' => 'Tanggal', 'align' => 'left'],
                ['key' => 'ref_no', 'label' => 'No. Jurnal', 'align' => 'left'],
                ['key' => 'category', 'label' => 'Tipe Mutasi', 'align' => 'left'],
                ['key' => 'description', 'label' => 'Keterangan Jurnal', 'align' => 'left'],
                ['key' => 'direction', 'label' => 'Arus', 'align' => 'center'],
                ['key' => 'amount', 'label' => 'Nominal (Rp)', 'align' => 'right', 'format' => 'rupiah'],
                ['key' => 'user_name', 'label' => 'Cabang', 'align' => 'left'],
            ],
            'items'             => $items,
            'action_link'       => $actionLink,
            'action_label'      => $actionLabel,
        ]);
    }

    /**
     * API Endpoint to trigger full synchronization of historical operations into Double-Entry General Ledger
     */
    public function syncJournals(Request $request)
    {
        $user = $request->user();
        $businessId = $user ? $user->business_id : null;

        $stats = AccountingService::syncAllHistoricalData($businessId);

        return response()->json([
            'message' => 'Sinkronisasi Buku Besar Jurnal Berpasangan (Double-Entry General Ledger) berhasil diselesaikan.',
            'stats'   => $stats,
        ]);
    }
}
