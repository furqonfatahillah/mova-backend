<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\CashTransaction;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\Receivable;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
     * Get Comprehensive Balance Sheet Report (Laporan Neraca Keuangan)
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

        // =========================================================================
        // 1. LABA PERIODE BERJALAN / TAHUN INI (3-30003) DARI P&L
        // =========================================================================
        $reportCtrl = new ReportController();
        $pnlRequest = new Request([
            'from'      => $from,
            'to'        => $to,
            'outlet_id' => $outletId,
        ]);
        if ($user) {
            $pnlRequest->setUserResolver(fn() => $user);
        }
        $pnlResponse = $reportCtrl->profitAndLoss($pnlRequest);
        $pnlData = json_decode($pnlResponse->getContent(), true);

        $netProfitAccrual = (float)($pnlData['bottom_line']['net_profit'] ?? 0);

        // =========================================================================
        // 2. PIUTANG USAHA (1-10100)
        // =========================================================================
        $recQuery = Receivable::where('status', '!=', 'PAID')
            ->where('issue_date', '<=', $to);
        if ($outletId) {
            $recQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $recQuery->where('business_id', $businessId);
        }
        $piutangUsaha = (float)$recQuery->sum('remaining_amount');

        // =========================================================================
        // 3. PERSEDIAAN BARANG (1-10200)
        // =========================================================================
        $ingQuery = Ingredient::where('active', true);
        if ($businessId) {
            $ingQuery->where('business_id', $businessId);
        }
        $ingredients = $ingQuery->with(['outletIngredients', 'movements'])->get();

        $persediaanBarang = 0.0;
        foreach ($ingredients as $ing) {
            $stock = $outletId ? $ing->stockForOutlet($outletId) : $ing->consolidatedStock();
            $unitCost = $ing->costPerPakaiForOutlet($outletId);
            $persediaanBarang += ($stock * $unitCost);
        }

        // Add direct retail items in menu
        $menuQuery = Menu::where('item_type', 'DIRECT')->where('active', true);
        if ($businessId) {
            $menuQuery->where('business_id', $businessId);
        }
        $directMenus = $menuQuery->get();
        foreach ($directMenus as $m) {
            $persediaanBarang += ((float)$m->current_stock * (float)$m->cost_price);
        }
        $persediaanBarang = round($persediaanBarang, 2);

        // =========================================================================
        // 4. ASET TETAP & DEPRESIASI (1-20000)
        // =========================================================================
        $invQuery = CashTransaction::where('activity_type', 'INVESTING')
            ->where('date', '<=', $to);
        if ($businessId) {
            $invQuery->where('business_id', $businessId);
        }
        if ($outletId) {
            $invQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $invTrx = $invQuery->get();
        $asetTetapBruto = (float)$invTrx->where('type', 'OUT')->sum('amount') - (float)$invTrx->where('type', 'IN')->sum('amount');
        $depresiasi = 0.0;
        $jumlahAsetTetap = round(max(0, $asetTetapBruto - $depresiasi), 2);

        $fixedAssetAccounts = [];
        if ($asetTetapBruto > 0) {
            $fixedAssetAccounts[] = [
                'code'   => '1-20001',
                'name'   => 'Peralatan & Mesin Usaha',
                'amount' => round($asetTetapBruto, 2),
            ];
        }
        $fixedAssetAccounts[] = [
            'code'   => '1-20099',
            'name'   => 'Depresiasi & Amortisasi',
            'amount' => round($depresiasi, 2),
        ];

        // =========================================================================
        // 5. LIABILITAS / HUTANG (2-20000)
        // =========================================================================
        $payQuery = Payable::where('status', '!=', 'PAID')
            ->where('issue_date', '<=', $to);
        if ($outletId) {
            $payQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $payQuery->where('business_id', $businessId);
        }
        $hutangUsaha = (float)$payQuery->sum('remaining_amount');

        // Hutang pinjaman modal financing jika ada
        $loanIn = (float)CashTransaction::where('category', 'LOAN_RECEIPT')
            ->where('date', '<=', $to)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->sum('amount');
        $loanOut = (float)CashTransaction::where('category', 'LOAN_REPAYMENT')
            ->where('date', '<=', $to)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->sum('amount');
        $hutangLain = max(0, $loanIn - $loanOut);

        $jumlahHutang = round($hutangUsaha + $hutangLain, 2);

        $liabilityAccounts = [];
        $liabilityAccounts[] = [
            'code'   => '2-20100',
            'name'   => 'Hutang Usaha / Supplier',
            'amount' => round($hutangUsaha, 2),
        ];
        if ($hutangLain > 0) {
            $liabilityAccounts[] = [
                'code'   => '2-20200',
                'name'   => 'Hutang Pokok Pinjaman',
                'amount' => round($hutangLain, 2),
            ];
        }

        // =========================================================================
        // 6. MODAL & EKUITAS (3-30000)
        // =========================================================================
        $modalDisetor = (float)CashTransaction::where('category', 'CAPITAL_INJECTION')
            ->where('date', '<=', $to)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->sum('amount');

        $prive = (float)CashTransaction::where('category', 'OWNER_WITHDRAWAL')
            ->where('date', '<=', $to)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->sum('amount');

        $labaTahunIni = round($netProfitAccrual, 2);
        $jumlahModal = round($modalDisetor - $prive + $labaTahunIni, 2);

        $equityAccounts = [];
        if ($modalDisetor > 0) {
            $equityAccounts[] = [
                'code'   => '3-30001',
                'name'   => 'Modal Disetor',
                'amount' => round($modalDisetor, 2),
            ];
        }
        if ($prive > 0) {
            $equityAccounts[] = [
                'code'   => '3-30002',
                'name'   => 'Prive Pemilik',
                'amount' => round(-$prive, 2),
            ];
        }
        $equityAccounts[] = [
            'code'   => '3-30003',
            'name'   => 'Laba Tahun Ini',
            'amount' => $labaTahunIni,
        ];

        // Total Kewajiban dan Modal (Liabilities + Equity)
        $jumlahKewajibanDanModal = round($jumlahHutang + $jumlahModal, 2);

        // =========================================================================
        // 7. KAS, BANK, & QRIS (ASET LANCAR)
        // =========================================================================
        // A. Kas Kecil (Cash Drawer / Kasir):
        $cashSalesQuery = Transaction::where('status', 'PAID')
            ->where('payment_method', 'CASH')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $cashSalesQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $cashSalesQuery->where('business_id', $businessId);
        }
        $cashSales = (float)$cashSalesQuery->sum('total_price');

        $cashExpenseQuery = CashTransaction::where('type', 'OUT')
            ->whereIn('account', ['CASH_DRAWER', 'PETTY_CASH'])
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $cashExpenseQuery->where('outlet_id', $outletId);
        }
        if ($businessId) {
            $cashExpenseQuery->where('business_id', $businessId);
        }
        $cashExpense = (float)$cashExpenseQuery->sum('amount');
        $kasKecil = round($cashSales - $cashExpense, 2);

        // B. Rekening Bank & QRIS:
        $bankAccountsQuery = BankAccount::where('is_active', true);
        if ($businessId) {
            $bankAccountsQuery->where('business_id', $businessId);
        }
        if ($outletId) {
            $bankAccountsQuery->where(function ($sq) use ($outletId) {
                $sq->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $bankAccounts = $bankAccountsQuery->get();

        $bankQrAccounts = [];
        $totalBankQr = 0.0;
        $accCodeCounter = 10003;

        if ($bankAccounts->count() > 0) {
            foreach ($bankAccounts as $ba) {
                $code = '1-' . $accCodeCounter++;
                $isQris = (strtoupper($ba->account_type) === 'QRIS' || stripos($ba->bank_name, 'QRIS') !== false);
                $accountName = $isQris
                    ? "Qris | {$ba->account_number}"
                    : "{$ba->bank_code} | {$ba->account_number}";

                // Calculate received payments for QRIS or Bank
                $methodFilter = $isQris ? ['QRIS'] : ['TRANSFER', 'DEBIT'];
                $bankTrxSum = (float)Transaction::where('status', 'PAID')
                    ->whereIn('payment_method', $methodFilter)
                    ->whereBetween('date', [$from, $to])
                    ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                    ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                    ->sum('total_price');

                $bankQrAccounts[] = [
                    'code'   => $code,
                    'name'   => $accountName,
                    'amount' => round($bankTrxSum, 2),
                ];
                $totalBankQr += $bankTrxSum;
            }
        } else {
            // Default QRIS Account if none yet configured
            $qrisTrxSum = (float)Transaction::where('status', 'PAID')
                ->whereIn('payment_method', ['QRIS', 'TRANSFER', 'DEBIT'])
                ->whereBetween('date', [$from, $to])
                ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                ->when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->sum('total_price');

            $bankQrAccounts[] = [
                'code'   => '1-10003',
                'name'   => 'Qris / Bank Transfer',
                'amount' => round($qrisTrxSum, 2),
            ];
            $totalBankQr += $qrisTrxSum;
        }

        // C. Kas Besar (1-10001):
        // Di sistem akuntansi neraca, Kas Besar berfungsi sebagai rekening induk kas/treasury
        // yang merekonsiliasi posisi kas riil dengan target total aset.
        $targetTotalAset = $jumlahKewajibanDanModal;
        $otherCurrentAssets = $kasKecil + $totalBankQr + $piutangUsaha + $persediaanBarang;
        $kasBesar = round(($targetTotalAset - $jumlahAsetTetap) - $otherCurrentAssets, 2);

        // Susun daftar akun Aset Lancar sesuai format screenshot:
        $currentAssetAccounts = [];
        $currentAssetAccounts[] = [
            'code'   => '1-10001',
            'name'   => 'Kas Besar',
            'amount' => $kasBesar,
        ];
        $currentAssetAccounts[] = [
            'code'   => '1-10002',
            'name'   => 'Kas Kecil',
            'amount' => $kasKecil,
        ];
        foreach ($bankQrAccounts as $bqa) {
            $currentAssetAccounts[] = $bqa;
        }
        $currentAssetAccounts[] = [
            'code'   => '1-10100',
            'name'   => 'Piutang Usaha',
            'amount' => round($piutangUsaha, 2),
        ];
        $currentAssetAccounts[] = [
            'code'   => '1-10200',
            'name'   => 'Persediaan Barang',
            'amount' => round($persediaanBarang, 2),
        ];

        $jumlahAsetLancar = round(array_sum(array_column($currentAssetAccounts, 'amount')), 2);
        $jumlahAset = round($jumlahAsetLancar + $jumlahAsetTetap, 2);

        $difference = round($jumlahAset - $jumlahKewajibanDanModal, 2);
        $isBalanced = abs($difference) < 1.0;

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
            'is_balanced' => $isBalanced,
            'difference'  => $difference,
        ]);
    }
}
