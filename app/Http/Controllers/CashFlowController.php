<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\Transaction;
use App\Models\StockMovement;
use App\Models\OperatingExpense;
use App\Models\WasteLog;
use App\Http\Controllers\ReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashFlowController extends Controller
{
    /**
     * Comprehensive Real Cash Flow Statement (Arus Kas Nyata: Kas Fisik vs Laba Akrual)
     */
    public function statement(Request $request)
    {
        $from = $request->input('from', date('Y-m-01'));
        $to   = $request->input('to', date('Y-m-d'));
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        // =========================================================================
        // 1. ARUS KAS DARI AKTIVITAS OPERASI (OPERATING CASH FLOW / OCF)
        // =========================================================================
        
        // A. Penerimaan Kas dari Penjualan Kasir (Cash Inflows from Sales)
        $trxQuery = Transaction::where('status', 'PAID')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $trxQuery->where('outlet_id', $outletId);
        }
        $transactions = $trxQuery->get();

        $cashSales = 0.0;
        $qrisSales = 0.0;
        $transferSales = 0.0;
        $debitSales = 0.0;
        $otherSales = 0.0;

        foreach ($transactions as $t) {
            $amt = (float)$t->total_price;
            $method = strtoupper($t->payment_method ?: 'CASH');

            if ($method === 'CASH') {
                $cashSales += $amt;
            } elseif ($method === 'QRIS') {
                $qrisSales += $amt;
            } elseif ($method === 'TRANSFER') {
                $transferSales += $amt;
            } elseif ($method === 'DEBIT') {
                $debitSales += $amt;
            } else {
                $otherSales += $amt;
            }
        }

        $totalSalesReceipts = $cashSales + $qrisSales + $transferSales + $debitSales + $otherSales;

        // Penerimaan Operasional Lainnya dari buku kas manual
        $extraOpInQuery = CashTransaction::where('activity_type', 'OPERATING')
            ->where('type', 'IN')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $extraOpInQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $extraOpIn = (float)$extraOpInQuery->sum('amount');
        $totalOperatingInflows = $totalSalesReceipts + $extraOpIn;

        // B. Pengeluaran Kas untuk Belanja Persediaan Bahan Baku (Cash Paid for Inventory Purchases)
        $movQuery = StockMovement::with(['ingredient'])
            ->where('type', 'PURCHASE')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $movQuery->where('outlet_id', $outletId);
        }
        $purchaseMovements = $movQuery->get();

        $stockPurchasesTotal = 0.0;
        $purchaseItemsSummary = [];
        foreach ($purchaseMovements as $m) {
            $ing = $m->ingredient;
            $konversi = $ing ? max((float)$ing->konversi, 1) : 1;
            $unitCost = $m->unit_price > 0 ? (float)$m->unit_price : ($ing ? (float)$ing->harga / $konversi : 0);
            $totalCost = $m->total_price > 0 ? (float)$m->total_price : round((float)$m->qty * $unitCost, 2);
            $stockPurchasesTotal += $totalCost;

            if ($ing) {
                $ingId = $ing->id;
                if (!isset($purchaseItemsSummary[$ingId])) {
                    $purchaseItemsSummary[$ingId] = [
                        'name'  => $ing->name,
                        'unit'  => $ing->unit_pakai,
                        'qty'   => 0,
                        'total' => 0,
                    ];
                }
                $purchaseItemsSummary[$ingId]['qty'] += (float)$m->qty;
                $purchaseItemsSummary[$ingId]['total'] += $totalCost;
            }
        }
        usort($purchaseItemsSummary, fn($a, $b) => $b['total'] <=> $a['total']);
        $topPurchases = array_slice($purchaseItemsSummary, 0, 5);

        // Tambahan pembelian bahan langsung dari buku kas jika ada
        $extraSuppQuery = CashTransaction::where('category', 'SUPPLIER_PURCHASE')
            ->where('type', 'OUT')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $extraSuppQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $extraSupplierCash = (float)$extraSuppQuery->sum('amount');
        $totalInventoryCashOut = $stockPurchasesTotal + $extraSupplierCash;

        // C. Pengeluaran Kas untuk Beban Operasional Toko (Cash Paid for OPEX)
        $opexQuery = OperatingExpense::whereBetween('date', [$from, $to]);
        if ($outletId) {
            $opexQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $opexRecords = $opexQuery->get();
        $totalOpexCashOut = (float)$opexRecords->sum('amount');

        // Tambahan biaya operasional lain dari buku kas
        $extraOpExQuery = CashTransaction::where('category', 'OTHER_EXPENSE')
            ->where('type', 'OUT')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $extraOpExQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $extraOpExCash = (float)$extraOpExQuery->sum('amount');
        $totalOperatingExpensesCashOut = $totalOpexCashOut + $extraOpExCash;

        $totalOperatingOutflows = $totalInventoryCashOut + $totalOperatingExpensesCashOut;
        $netOperatingCashFlow = round($totalOperatingInflows - $totalOperatingOutflows, 2);

        // =========================================================================
        // 2. ARUS KAS DARI AKTIVITAS INVESTASI / CAPEX (INVESTING CASH FLOW)
        // =========================================================================
        $invQuery = CashTransaction::where('activity_type', 'INVESTING')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $invQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $investingTransactions = $invQuery->get();

        $investingInflows = (float)$investingTransactions->where('type', 'IN')->sum('amount');
        $investingOutflows = (float)$investingTransactions->where('type', 'OUT')->sum('amount');
        $netInvestingCashFlow = round($investingInflows - $investingOutflows, 2);

        // Rincian CapEx per kategori
        $capexBreakdown = [];
        $investingCategories = ['EQUIPMENT', 'RENOVATION', 'FURNITURE', 'TECH_POS', 'ASSET_SALE'];
        $catMeta = CashTransaction::categories();
        foreach ($investingCategories as $cKey) {
            $items = $investingTransactions->where('category', $cKey);
            $cSum = (float)$items->sum('amount');
            if ($cSum > 0) {
                $capexBreakdown[] = [
                    'category' => $cKey,
                    'label'    => $catMeta[$cKey]['label'] ?? $cKey,
                    'type'     => $catMeta[$cKey]['default_type'] ?? 'OUT',
                    'amount'   => $cSum,
                    'count'    => $items->count(),
                ];
            }
        }

        // =========================================================================
        // 3. ARUS KAS DARI AKTIVITAS PENDANAAN (FINANCING CASH FLOW / FCF)
        // =========================================================================
        $finQuery = CashTransaction::where('activity_type', 'FINANCING')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $finQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $financingTransactions = $finQuery->get();

        $financingInflows = (float)$financingTransactions->where('type', 'IN')->sum('amount');
        $financingOutflows = (float)$financingTransactions->where('type', 'OUT')->sum('amount');
        $netFinancingCashFlow = round($financingInflows - $financingOutflows, 2);

        // Rincian Pendanaan per kategori
        $financingBreakdown = [];
        $financingCategories = ['CAPITAL_INJECTION', 'OWNER_WITHDRAWAL', 'LOAN_RECEIPT', 'LOAN_REPAYMENT'];
        foreach ($financingCategories as $fKey) {
            $fItems = $financingTransactions->where('category', $fKey);
            $fSum = (float)$fItems->sum('amount');
            if ($fSum > 0) {
                $financingBreakdown[] = [
                    'category' => $fKey,
                    'label'    => $catMeta[$fKey]['label'] ?? $fKey,
                    'type'     => $catMeta[$fKey]['default_type'] ?? 'IN',
                    'amount'   => $fSum,
                    'count'    => $fItems->count(),
                ];
            }
        }

        // =========================================================================
        // 4. TOTAL PERUBAHAN KAS BERSIH (NET CHANGE IN CASH)
        // =========================================================================
        $netCashFlow = round($netOperatingCashFlow + $netInvestingCashFlow + $netFinancingCashFlow, 2);

        // =========================================================================
        // 5. JEMBATAN REKONSILIASI: LABA AKRUAL (P&L) VS ARUS KAS NYATA
        // =========================================================================
        $reportCtrl = new ReportController();
        $plRequest = new Request([
            'from'      => $from,
            'to'        => $to,
            'outlet_id' => $outletId,
        ]);
        $plResponse = $reportCtrl->profitAndLoss($plRequest);
        $plData = json_decode($plResponse->getContent(), true);

        $accrualNetProfit = (float)($plData['bottom_line']['net_profit'] ?? 0);
        $cogsTheoretical = (float)($plData['cogs']['total_cogs'] ?? 0);
        $wasteLossNonCash = (float)($plData['waste']['total_waste_loss'] ?? 0);

        // Selisih antara Belanja Stok Riil vs HPP Teoretis (Working Capital Inventory Build-up)
        // Jika Belanja Stok > HPP Resep -> Kas tertahan di chiller/gudang!
        $inventoryCapitalChange = round($totalInventoryCashOut - $cogsTheoretical, 2);

        $reconciliationSteps = [
            [
                'step'        => 'accrual_profit',
                'title'       => 'Laba Bersih Usaha (P&L Akrual)',
                'description' => 'Keuntungan di atas kertas operasional toko berdasarkan transaksi penjualan',
                'amount'      => $accrualNetProfit,
                'effect'      => 'START',
            ],
            [
                'step'        => 'cogs_addback',
                'title'       => '(+) Penyesuaian HPP Resep Non-Kas',
                'description' => 'Bahan baku yang dimasak adalah beban di P&L, namun uang kas tidak keluar saat makanan dimasak',
                'amount'      => $cogsTheoretical,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'actual_purchases',
                'title'       => '(-) Uang Kas Dibelanjakan untuk Stok Bahan Baku Riil',
                'description' => 'Seluruh uang kas keluar untuk beli stok bahan mentah (termasuk yang masih menumpuk di chiller/gudang)',
                'amount'      => -$totalInventoryCashOut,
                'effect'      => 'SUBTRACT',
            ],
            [
                'step'        => 'waste_addback',
                'title'       => '(+) Penyesuaian Kerugian Waste Non-Kas',
                'description' => 'Bahan basi/rusak mengurangi laba di P&L, tetapi tidak ada uang kas keluar saat bahan dibuang',
                'amount'      => $wasteLossNonCash,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'capex_deduct',
                'title'       => '(-) Belanja Modal / Aset Resto (CapEx)',
                'description' => 'Pengeluaran kas untuk beli kulkas, chiller, renovasi toko, dan mesin baru',
                'amount'      => -$investingOutflows,
                'effect'      => 'SUBTRACT',
            ],
            [
                'step'        => 'asset_sale_add',
                'title'       => '(+) Penerimaan Penjualan Aset Bekas',
                'description' => 'Kas masuk dari penjualan peralatan atau aset bekas',
                'amount'      => $investingInflows,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'owner_withdrawal_deduct',
                'title'       => '(-) Prive / Penarikan Dana Tunai oleh Owner',
                'description' => 'Pengambilan kas pribadi atau dividen berjalan oleh pemilik resto',
                'amount'      => -$financingOutflows,
                'effect'      => 'SUBTRACT',
            ],
            [
                'step'        => 'capital_injection_add',
                'title'       => '(+) Setoran Modal Tambahan / Pinjaman',
                'description' => 'Injeksi dana segar dari owner, partner bisnis, atau pinjaman modal',
                'amount'      => $financingInflows,
                'effect'      => 'ADD',
            ],
            [
                'step'        => 'net_cash_result',
                'title'       => '(=) Perubahan Bersih Kas Riil (Net Cash Flow)',
                'description' => 'Kelebihan / kekurangan kas fisik riil yang benar-benar bertambah/berkurang di rekening & kasir',
                'amount'      => $netCashFlow,
                'effect'      => 'TOTAL',
            ],
        ];

        return response()->json([
            'period' => [
                'from'      => $from,
                'to'        => $to,
                'outlet_id' => $outletId,
            ],
            'summary' => [
                'net_operating_cash_flow' => $netOperatingCashFlow,
                'net_investing_cash_flow' => $netInvestingCashFlow,
                'net_financing_cash_flow' => $netFinancingCashFlow,
                'net_cash_flow'           => $netCashFlow,
                'accrual_net_profit'      => $accrualNetProfit,
                'inventory_cash_trapped'  => $inventoryCapitalChange,
                'liquidity_status'        => $netCashFlow >= 0 ? 'SURPLUS' : 'DEFICIT',
                'liquidity_label'         => $netCashFlow >= 0 ? 'Kas Surplus (Likuid Prima)' : 'Kas Defisit (Ketat / Waspada)',
                'liquidity_color'         => $netCashFlow >= 0 ? '#10B981' : '#EF4444',
            ],
            'operating' => [
                'inflows' => [
                    'cash_sales'      => round($cashSales, 2),
                    'qris_sales'      => round($qrisSales, 2),
                    'transfer_sales'  => round($transferSales, 2),
                    'debit_sales'     => round($debitSales, 2),
                    'other_sales'     => round($otherSales, 2),
                    'extra_income'    => round($extraOpIn, 2),
                    'total_inflows'   => round($totalOperatingInflows, 2),
                ],
                'outflows' => [
                    'stock_purchases' => round($totalInventoryCashOut, 2),
                    'opex_expenses'   => round($totalOperatingExpensesCashOut, 2),
                    'total_outflows'  => round($totalOperatingOutflows, 2),
                ],
                'top_purchases' => $topPurchases,
                'net'           => $netOperatingCashFlow,
            ],
            'investing' => [
                'inflows'   => round($investingInflows, 2),
                'outflows'  => round($investingOutflows, 2),
                'breakdown' => $capexBreakdown,
                'net'       => $netInvestingCashFlow,
            ],
            'financing' => [
                'inflows'   => round($financingInflows, 2),
                'outflows'  => round($financingOutflows, 2),
                'breakdown' => $financingBreakdown,
                'net'       => $netFinancingCashFlow,
            ],
            'reconciliation' => [
                'steps'                    => $reconciliationSteps,
                'inventory_capital_change' => $inventoryCapitalChange,
                'accrual_profit'           => $accrualNetProfit,
                'cash_profit'              => $netCashFlow,
                'discrepancy_explanation'  => $inventoryCapitalChange > 0 
                    ? "Terdapat uang kas sebesar Rp " . number_format($inventoryCapitalChange, 0, ',', '.') . " yang terkunci dalam bentuk stok bahan baku di gudang/chiller (pembelian stok lebih besar dari bahan terpakai)."
                    : "Pembelian stok bahan baku lebih hemat dibandingkan pemakaian menu terjual.",
            ],
        ]);
    }

    /**
     * List manual cash transactions (CapEx, Financing, Extra Cash Entries)
     */
    public function index(Request $request)
    {
        $query = CashTransaction::with(['outlet', 'user'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        if ($isOutletBounded) {
            $query->where('outlet_id', (int)$user->outlet_id);
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('from')) {
            $query->where('date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('date', '<=', $request->to);
        }

        if ($request->filled('activity_type') && $request->activity_type !== 'ALL') {
            $query->where('activity_type', $request->activity_type);
        }

        if ($request->filled('category') && $request->category !== 'ALL') {
            $query->where('category', $request->category);
        }

        if ($request->filled('search')) {
            $search = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('transaction_no', 'like', $search)
                  ->orWhere('notes', 'like', $search);
            });
        }

        return response()->json($query->limit(300)->get());
    }

    /**
     * Store a new manual cash transaction
     */
    public function store(Request $request)
    {
        $validCategories = array_keys(CashTransaction::categories());
        $validActivities = array_keys(CashTransaction::activityTypes());
        $validAccounts = array_keys(CashTransaction::accounts());

        $data = $request->validate([
            'date'           => 'required|date',
            'activity_type'  => 'required|string|in:' . implode(',', $validActivities),
            'category'       => 'required|string|in:' . implode(',', $validCategories),
            'type'           => 'required|string|in:IN,OUT',
            'name'           => 'required|string|max:255',
            'amount'         => 'required|numeric|min:0.01',
            'account'        => 'nullable|string|in:' . implode(',', $validAccounts),
            'payment_method' => 'nullable|string|max:30',
            'outlet_id'      => 'nullable|exists:outlets,id',
            'notes'          => 'nullable|string|max:1000',
            'receipt_img'    => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $businessId = $user->business_id ?? null;
        $transactionNo = CashTransaction::generateTransactionNo($businessId, $data['date']);
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($data['outlet_id'] ?? null);

        $trx = CashTransaction::create([
            'business_id'    => $businessId,
            'outlet_id'      => $outletId,
            'transaction_no' => $transactionNo,
            'date'           => $data['date'],
            'type'           => $data['type'],
            'activity_type'  => $data['activity_type'],
            'category'       => $data['category'],
            'name'           => $data['name'],
            'amount'         => (float)$data['amount'],
            'account'        => $data['account'] ?? 'BANK_MAIN',
            'payment_method' => $data['payment_method'] ?? 'TRANSFER',
            'notes'          => $data['notes'] ?? null,
            'receipt_img'    => $data['receipt_img'] ?? null,
            'user_id'        => $user->id,
            'created_by'     => $user->id,
        ]);

        return response()->json([
            'message' => 'Transaksi kas berhasil dicatat',
            'data'    => $trx->fresh(['outlet', 'user']),
        ], 201);
    }

    /**
     * Show a single cash transaction
     */
    public function show($id)
    {
        $trx = CashTransaction::with(['outlet', 'user'])->findOrFail($id);
        return response()->json($trx);
    }

    /**
     * Update an existing cash transaction
     */
    public function update(Request $request, $id)
    {
        $trx = CashTransaction::findOrFail($id);

        $validCategories = array_keys(CashTransaction::categories());
        $validActivities = array_keys(CashTransaction::activityTypes());
        $validAccounts = array_keys(CashTransaction::accounts());

        $data = $request->validate([
            'date'           => 'sometimes|required|date',
            'activity_type'  => 'sometimes|required|string|in:' . implode(',', $validActivities),
            'category'       => 'sometimes|required|string|in:' . implode(',', $validCategories),
            'type'           => 'sometimes|required|string|in:IN,OUT',
            'name'           => 'sometimes|required|string|max:255',
            'amount'         => 'sometimes|required|numeric|min:0.01',
            'account'        => 'nullable|string|in:' . implode(',', $validAccounts),
            'payment_method' => 'nullable|string|max:30',
            'outlet_id'      => 'nullable|exists:outlets,id',
            'notes'          => 'nullable|string|max:1000',
            'receipt_img'    => 'nullable|string|max:255',
        ]);

        $trx->update([
            'date'           => $data['date'] ?? $trx->date,
            'activity_type'  => $data['activity_type'] ?? $trx->activity_type,
            'category'       => $data['category'] ?? $trx->category,
            'type'           => $data['type'] ?? $trx->type,
            'name'           => $data['name'] ?? $trx->name,
            'amount'         => isset($data['amount']) ? (float)$data['amount'] : $trx->amount,
            'account'        => $data['account'] ?? $trx->account,
            'payment_method' => $data['payment_method'] ?? $trx->payment_method,
            'outlet_id'      => array_key_exists('outlet_id', $data) ? $data['outlet_id'] : $trx->outlet_id,
            'notes'          => array_key_exists('notes', $data) ? $data['notes'] : $trx->notes,
            'receipt_img'    => array_key_exists('receipt_img', $data) ? $data['receipt_img'] : $trx->receipt_img,
            'updated_by'     => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Transaksi kas berhasil diperbarui',
            'data'    => $trx->fresh(['outlet', 'user']),
        ]);
    }

    /**
     * Delete a cash transaction
     */
    public function destroy($id)
    {
        $trx = CashTransaction::findOrFail($id);
        $trx->delete();

        return response()->json([
            'message' => 'Catatan transaksi kas berhasil dihapus',
        ]);
    }

    /**
     * Categories and activity types dictionary for frontend dropdowns
     */
    public function categories()
    {
        return response()->json([
            'categories'     => CashTransaction::categories(),
            'activity_types' => CashTransaction::activityTypes(),
            'accounts'       => CashTransaction::accounts(),
        ]);
    }
}
