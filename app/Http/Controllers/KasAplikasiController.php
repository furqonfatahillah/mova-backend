<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\CashTransaction;
use App\Models\Transaction;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class KasAplikasiController extends Controller
{
    /**
     * Get summary of Kas Aplikasi (QRIS balance, sales in, deposits out, bank accounts, history)
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = null;
        if ($isOutletBounded) {
            $outletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $outletId = (int)$request->outlet_id;
        }

        // Date filter
        $from = $request->input('from');
        $to = $request->input('to');
        $today = now()->toDateString();
        $thisMonthStart = now()->startOfMonth()->toDateString();
        $thisMonthEnd = now()->endOfMonth()->toDateString();

        // 1. All-time Kas Aplikasi Sales Total (QRIS + E-Commerce / Online Delivery: GrabFood, GoFood, ShopeeFood, dll)
        $appSalesBase = Transaction::where('business_id', $businessId)
            ->where('status', 'PAID')
            ->where(function ($q) {
                $q->where('payment_method', 'like', '%QRIS%')
                  ->orWhere('payment_method', 'like', '%GRAB%')
                  ->orWhere('payment_method', 'like', '%GOFOOD%')
                  ->orWhere('payment_method', 'like', '%GOJEK%')
                  ->orWhere('payment_method', 'like', '%SHOPEE%')
                  ->orWhere('payment_method', 'like', '%ECOMMERCE%')
                  ->orWhere('payment_method', 'like', '%E-COMMERCE%')
                  ->orWhere('payment_method', 'like', '%TIKTOK%')
                  ->orWhere('payment_method', 'like', '%TOKOPEDIA%')
                  ->orWhere('payment_method', 'like', '%DELIVERY%')
                  ->orWhere('payment_method', 'like', '%ONLINE%')
                  ->orWhere('payment_method', 'like', '%APLIKASI%');
            });

        if ($outletId) {
            $appSalesBase->where('outlet_id', $outletId);
        }

        $sumAppSales = function ($query) {
            return (float) ($query->selectRaw('COALESCE(SUM(COALESCE(total_price, subtotal, 0)), 0) as total')->value('total') ?? 0);
        };

        $allTimeQrisSales = $sumAppSales(clone $appSalesBase);
        $todayQrisSales = $sumAppSales((clone $appSalesBase)->where('date', $today));
        $monthQrisSales = $sumAppSales((clone $appSalesBase)->whereBetween('date', [$thisMonthStart, $thisMonthEnd]));

        // Filtered period sales
        $appSalesQuery = clone $appSalesBase;
        if ($from) {
            $appSalesQuery->where('date', '>=', $from);
        }
        if ($to) {
            $appSalesQuery->where('date', '<=', $to);
        }
        $periodQrisSales = $sumAppSales($appSalesQuery);

        // 2. All-time Setoran Kas Aplikasi ke Bank (Outgoing funds from Kas Aplikasi to Bank)
        $depositBase = CashTransaction::where('business_id', $businessId)
            ->where('status', 'APPROVED')
            ->whereIn('category', ['SETOR_KAS_APLIKASI', 'SETORAN_QRIS', 'SETORAN_KAS_APLIKASI', 'DEPOSIT_KAS_APLIKASI']);

        if ($outletId) {
            $depositBase->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        $allTimeDeposits = (float) (clone $depositBase)->sum('amount');
        $todayDeposits = (float) (clone $depositBase)->where('date', $today)->sum('amount');
        $monthDeposits = (float) (clone $depositBase)->whereBetween('date', [$thisMonthStart, $thisMonthEnd])->sum('amount');

        // Filtered period deposits
        $depositQuery = clone $depositBase;
        if ($from) {
            $depositQuery->where('date', '>=', $from);
        }
        if ($to) {
            $depositQuery->where('date', '<=', $to);
        }
        $periodDeposits = (float) $depositQuery->sum('amount');

        // Current real-time available balance in Kas Aplikasi
        $currentBalance = round($allTimeQrisSales - $allTimeDeposits, 2);

        // 3. Registered Bank Accounts for this Business / Outlet
        $bankAccountsQuery = BankAccount::where('business_id', $businessId)
            ->where('is_active', true)
            ->where(function ($q) use ($outletId) {
                if ($outletId) {
                    $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                }
            });

        if ($outletId) {
            $bankAccountsQuery->orderByRaw('CASE WHEN outlet_id = ? THEN 0 ELSE 1 END', [$outletId]);
        }

        $bankAccounts = $bankAccountsQuery
            ->orderBy('is_primary', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        // 4. Primary QRIS Image URL
        $primaryAccount = $bankAccounts->firstWhere('is_primary', true) ?: $bankAccounts->first();
        $qrisImageUrl = $primaryAccount?->qr_image_url;

        // 5. Recent History of Kas Aplikasi (Both QRIS/E-Commerce In & Bank Deposits Out)
        // A. Sales In (QRIS & E-Commerce)
        $appTxQuery = Transaction::with(['outlet', 'customer', 'shift', 'depositCashTransaction'])
            ->where('business_id', $businessId)
            ->where('status', 'PAID')
            ->where(function ($q) {
                $q->where('payment_method', 'like', '%QRIS%')
                  ->orWhere('payment_method', 'like', '%GRAB%')
                  ->orWhere('payment_method', 'like', '%GOFOOD%')
                  ->orWhere('payment_method', 'like', '%GOJEK%')
                  ->orWhere('payment_method', 'like', '%SHOPEE%')
                  ->orWhere('payment_method', 'like', '%ECOMMERCE%')
                  ->orWhere('payment_method', 'like', '%E-COMMERCE%')
                  ->orWhere('payment_method', 'like', '%TIKTOK%')
                  ->orWhere('payment_method', 'like', '%TOKOPEDIA%')
                  ->orWhere('payment_method', 'like', '%DELIVERY%')
                  ->orWhere('payment_method', 'like', '%ONLINE%')
                  ->orWhere('payment_method', 'like', '%APLIKASI%');
            })
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        if ($outletId) {
            $appTxQuery->where('outlet_id', $outletId);
        }
        if ($from) $appTxQuery->where('date', '>=', $from);
        if ($to) $appTxQuery->where('date', '<=', $to);

        $qrisTransactions = $appTxQuery->limit(500)->get()->map(function ($t) {
            $pm = strtoupper(trim((string)$t->payment_method));
            $methodCat = 'OTHER';
            if (str_contains($pm, 'QRIS')) {
                $methodCat = 'QRIS';
            } elseif (str_contains($pm, 'GRAB')) {
                $methodCat = 'GRAB';
            } elseif (str_contains($pm, 'GOFOOD') || str_contains($pm, 'GOJEK')) {
                $methodCat = 'GOFOOD';
            } elseif (str_contains($pm, 'SHOPEE')) {
                $methodCat = 'SHOPEEFOOD';
            }

            $label = match($methodCat) {
                'QRIS'        => 'Penerimaan QRIS',
                'GRAB'        => 'Penerimaan GrabFood',
                'GOFOOD'      => 'Penerimaan GoFood',
                'SHOPEEFOOD'  => 'Penerimaan ShopeeFood',
                default       => 'Penerimaan ' . ($pm ?: 'Aplikasi'),
            };

            $totalAmt = (float)($t->total_price ?: $t->subtotal ?: 0);
            $depAmt = (float)($t->deposited_amount ?: 0);
            $isDep = (bool)($t->is_deposited || ($depAmt >= $totalAmt && $totalAmt > 0) || $t->deposit_cash_transaction_id);
            $undepAmt = max(0, round($totalAmt - $depAmt, 2));

            return [
                'id'                          => 'APP-' . $t->id,
                'source_id'                   => $t->id,
                'type'                        => 'IN',
                'method_category'             => $methodCat,
                'type_label'                  => $label,
                'date'                        => $t->date ? (is_string($t->date) ? substr($t->date, 0, 10) : $t->date->format('Y-m-d')) : null,
                'time'                        => $t->created_at ? $t->created_at->format('H:i') : null,
                'reference_no'                => $t->order_number ?: ('TRX-' . $t->id),
                'name'                        => 'Penjualan ' . ($pm ?: 'Aplikasi') . ' #' . ($t->order_number ?: $t->id) . ' (' . ($t->customer_name ?: 'Pelanggan') . ')',
                'outlet_name'                 => $t->outlet?->name ?? 'Cabang',
                'amount'                      => $totalAmt,
                'payment_method'              => $pm ?: 'KAS_APLIKASI',
                'account'                     => 'KAS_APLIKASI',
                'status'                      => 'PAID',
                'is_deposited'                => $isDep,
                'deposited_amount'            => $depAmt,
                'deposited_at'                => $t->deposited_at ? (is_string($t->deposited_at) ? substr($t->deposited_at, 0, 16) : $t->deposited_at->format('Y-m-d H:i')) : null,
                'deposit_cash_transaction_id' => $t->deposit_cash_transaction_id,
                'deposit_reference_no'        => $t->depositCashTransaction?->transaction_no,
                'undeposited_amount'          => $isDep ? 0 : $undepAmt,
                'notes'                       => $t->notes,
                'created_at'                  => $t->created_at ? $t->created_at->toIso8601String() : null,
            ];
        });

        // B. Bank Deposits (OUT)
        $depTxQuery = CashTransaction::with(['outlet', 'user', 'approver', 'depositedTransactions'])
            ->where('business_id', $businessId)
            ->whereIn('category', ['SETOR_KAS_APLIKASI', 'SETORAN_QRIS', 'SETORAN_KAS_APLIKASI', 'DEPOSIT_KAS_APLIKASI'])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        if ($outletId) {
            $depTxQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        if ($from) $depTxQuery->where('date', '>=', $from);
        if ($to) $depTxQuery->where('date', '<=', $to);

        $depositTransactions = $depTxQuery->limit(500)->get()->map(function ($ct) {
            $linkedOrders = $ct->depositedTransactions->map(fn($tr) => $tr->order_number ?: ('#' . $tr->id))->values()->all();
            return [
                'id'              => 'DEP-' . $ct->id,
                'source_id'       => $ct->id,
                'type'            => 'OUT',
                'method_category' => 'DEPOSIT',
                'type_label'      => 'Setor ke Bank',
                'date'            => $ct->date ? (is_string($ct->date) ? substr($ct->date, 0, 10) : $ct->date->format('Y-m-d')) : null,
                'time'            => $ct->created_at ? $ct->created_at->format('H:i') : null,
                'reference_no'    => $ct->transaction_no ?: ('DEP-' . $ct->id),
                'name'            => $ct->name ?: 'Setoran Kas Aplikasi ke Bank',
                'outlet_name'     => $ct->outlet?->name ?? 'Semua Cabang',
                'amount'          => (float)$ct->amount,
                'payment_method'  => 'TRANSFER',
                'account'         => $ct->account ?: 'BANK',
                'status'          => $ct->status ?: 'APPROVED',
                'notes'           => $ct->notes,
                'receipt_img'     => $ct->receipt_img,
                'linked_orders'   => $linkedOrders,
                'linked_count'    => count($linkedOrders),
                'created_by'      => $ct->user?->name ?? 'Admin',
                'created_at'      => $ct->created_at ? $ct->created_at->toIso8601String() : null,
            ];
        });

        // Calculate breakdown by method with available balances
        $categorizeMethod = function ($paymentMethod) {
            $pm = strtoupper(trim((string)$paymentMethod));
            if (str_contains($pm, 'QRIS')) return 'QRIS';
            if (str_contains($pm, 'GRAB')) return 'GRAB';
            if (str_contains($pm, 'GOFOOD') || str_contains($pm, 'GOJEK')) return 'GOFOOD';
            if (str_contains($pm, 'SHOPEE')) return 'SHOPEEFOOD';
            return 'OTHER';
        };

        $categorizeDepositMethod = function ($pm, $name, $notes) {
            $str = strtoupper(trim("{$pm} {$name} {$notes}"));
            if (str_contains($str, 'QRIS')) return 'QRIS';
            if (str_contains($str, 'GRAB')) return 'GRAB';
            if (str_contains($str, 'GOFOOD') || str_contains($pm, 'GOJEK')) return 'GOFOOD';
            if (str_contains($str, 'SHOPEE')) return 'SHOPEEFOOD';
            return 'ALL';
        };

        $allTimeSalesByMethod = ['QRIS' => 0, 'GRAB' => 0, 'GOFOOD' => 0, 'SHOPEEFOOD' => 0, 'OTHER' => 0];
        $allAppSalesList = (clone $appSalesBase)->get(['id', 'total_price', 'subtotal', 'payment_method', 'is_deposited', 'deposited_amount']);
        foreach ($allAppSalesList as $sale) {
            $cat = $categorizeMethod($sale->payment_method);
            $amount = (float)($sale->total_price ?: $sale->subtotal ?: 0);
            $allTimeSalesByMethod[$cat] = ($allTimeSalesByMethod[$cat] ?? 0) + $amount;
        }

        $allTimeDepositsByMethod = ['QRIS' => 0, 'GRAB' => 0, 'GOFOOD' => 0, 'SHOPEEFOOD' => 0, 'ALL' => 0];
        $allAppDepositsList = (clone $depositBase)->get(['id', 'amount', 'payment_method', 'name', 'notes']);
        foreach ($allAppDepositsList as $dep) {
            $cat = $categorizeDepositMethod($dep->payment_method, $dep->name, $dep->notes);
            $allTimeDepositsByMethod[$cat] = ($allTimeDepositsByMethod[$cat] ?? 0) + (float)$dep->amount;
        }

        $channels = ['QRIS', 'GRAB', 'GOFOOD', 'SHOPEEFOOD'];
        $byMethodBreakdown = [];
        foreach ($channels as $ch) {
            $sales = (float)($allTimeSalesByMethod[$ch] ?? 0);
            $deposits = (float)($allTimeDepositsByMethod[$ch] ?? 0);
            $bal = max(0, round($sales - $deposits, 2));
            $matchingIn = $qrisTransactions->where('method_category', $ch);
            $undepositedIn = $matchingIn->where('is_deposited', false);
            $byMethodBreakdown[$ch] = [
                'sales'             => $sales,
                'deposits'          => $deposits,
                'balance'           => $bal,
                'total_amount'      => (float)$matchingIn->sum('amount'),
                'count'             => $matchingIn->count(),
                'undeposited_total' => (float)$undepositedIn->sum('undeposited_amount'),
                'undeposited_count' => $undepositedIn->count(),
            ];
        }

        $allUndeposited = $qrisTransactions->where('is_deposited', false);
        $byMethodBreakdown['ALL'] = [
            'sales'             => $allTimeQrisSales,
            'deposits'          => $allTimeDeposits,
            'balance'           => $currentBalance,
            'total_amount'      => $allTimeQrisSales,
            'count'             => $qrisTransactions->count(),
            'undeposited_total' => (float)$allUndeposited->sum('undeposited_amount'),
            'undeposited_count' => $allUndeposited->count(),
        ];
        $byMethodBreakdown['DEPOSIT'] = [
            'total_amount' => (float)$depositTransactions->sum('amount'),
            'count'        => $depositTransactions->count(),
        ];

        // Merge and sort all transactions by created_at desc
        $combinedHistory = $qrisTransactions->concat($depositTransactions)
            ->sortByDesc(fn($item) => ($item['date'] ?? '') . ' ' . ($item['time'] ?? ''))
            ->values();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'current_balance'        => $currentBalance,
                'all_time_qris_sales'    => $allTimeQrisSales,
                'all_time_deposits'      => $allTimeDeposits,
                'period_qris_sales'      => $periodQrisSales,
                'period_deposits'        => $periodDeposits,
                'today_qris_sales'       => $todayQrisSales,
                'today_deposits'         => $todayDeposits,
                'month_qris_sales'       => $monthQrisSales,
                'month_deposits'         => $monthDeposits,
                'by_method'              => $byMethodBreakdown,
                'qris_image_url'         => $qrisImageUrl,
                'primary_bank_account'   => $primaryAccount,
                'bank_accounts'          => $bankAccounts,
                'history'                => $combinedHistory,
                'qris_history'           => $qrisTransactions->values(),
                'deposit_history'        => $depositTransactions->values(),
            ],
        ]);
    }

    /**
     * Deposit funds from Kas Aplikasi (QRIS, ShopeeFood, GoFood, GrabFood) to Bank Account
     * Supports:
     * 1. Mode Checklist: deposit specific selected transactions (transaction_ids)
     * 2. Mode FIFO: deposit nominal amount, settled earliest transactions first (FIFO), with channel filter
     */
    public function deposit(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        $validated = $request->validate([
            'amount'            => 'nullable|numeric|min:0',
            'gross_amount'      => 'nullable|numeric|min:0',
            'net_amount'        => 'nullable|numeric|min:0',
            'admin_fee'         => 'nullable|numeric|min:0',
            'mode'              => 'nullable|string|in:FIFO,SELECTED,NOMINAL',
            'transaction_ids'   => 'nullable|array',
            'transaction_ids.*' => 'integer|exists:transactions,id',
            'channel'           => 'nullable|string|max:50',
            'bank_account_id'   => 'nullable|exists:bank_accounts,id',
            'bank_name'         => 'nullable|string|max:100',
            'account_number'    => 'nullable|string|max:50',
            'account_holder'    => 'nullable|string|max:255',
            'outlet_id'         => 'nullable|exists:outlets,id',
            'date'              => 'nullable|date',
            'notes'             => 'nullable|string|max:500',
            'receipt_img'       => 'nullable|string',
        ]);

        $outletId = $validated['outlet_id'] ?? ($user->outlet_id ?: null);
        $date = $validated['date'] ?? now()->toDateString();
        $channel = strtoupper(trim((string)($validated['channel'] ?? 'ALL')));
        if (!in_array($channel, ['ALL', 'QRIS', 'GRAB', 'GOFOOD', 'SHOPEEFOOD'])) {
            $channel = 'ALL';
        }

        $channelLabels = [
            'ALL'        => 'Semua Saldo Aplikasi',
            'QRIS'       => 'QRIS',
            'GRAB'       => 'GrabFood',
            'GOFOOD'     => 'GoFood',
            'SHOPEEFOOD' => 'ShopeeFood',
        ];
        $channelLabel = $channelLabels[$channel] ?? 'Aplikasi';

        $mode = strtoupper(trim((string)($validated['mode'] ?? '')));
        $hasSelectedIds = !empty($validated['transaction_ids']) && is_array($validated['transaction_ids']) && count($validated['transaction_ids']) > 0;
        $isChecklistMode = $hasSelectedIds || $mode === 'SELECTED';

        $selectedTransactions = collect();
        if ($isChecklistMode) {
            $selectedTransactions = Transaction::where('business_id', $businessId)
                ->whereIn('id', $validated['transaction_ids'])
                ->where('status', 'PAID')
                ->where('is_deposited', false)
                ->get();

            if ($selectedTransactions->isEmpty()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Tidak ada transaksi belum disetor yang ditemukan dari pilihan Anda.',
                ], 422);
            }

            // Calculate gross amount from selected transactions
            $calculatedSelectedAmount = $selectedTransactions->sum(function ($t) {
                $totalT = (float)($t->total_price ?: $t->subtotal ?: 0);
                $depT = (float)($t->deposited_amount ?: 0);
                return max(0, $totalT - $depT);
            });

            $grossAmount = (float)($validated['gross_amount'] ?? $validated['amount'] ?? 0);
            if ($grossAmount <= 0) {
                $grossAmount = $calculatedSelectedAmount;
            }
        } else {
            $grossAmount = (float)($validated['gross_amount'] ?? $validated['amount'] ?? 0);
        }

        if ($grossAmount <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Nominal kotor / nilai order harus lebih besar dari 0.',
            ], 422);
        }

        // Hitung biaya admin dan penerimaan bersih
        $adminFee = 0.0;
        if (isset($validated['admin_fee']) && (float)$validated['admin_fee'] > 0) {
            $adminFee = round((float)$validated['admin_fee'], 2);
        } elseif (isset($validated['net_amount']) && (float)$validated['net_amount'] > 0 && (float)$validated['net_amount'] < $grossAmount) {
            $adminFee = round($grossAmount - (float)$validated['net_amount'], 2);
        }

        $netAmount = max(0, round($grossAmount - $adminFee, 2));
        $amount = $grossAmount;

        // Calculate available balance for selected channel (or all Kas Aplikasi)
        $appSalesBase = Transaction::where('business_id', $businessId)->where('status', 'PAID');
        if ($outletId) {
            $appSalesBase->where('outlet_id', $outletId);
        }

        if ($channel === 'QRIS') {
            $appSalesBase->where('payment_method', 'like', '%QRIS%');
        } elseif ($channel === 'GRAB') {
            $appSalesBase->where('payment_method', 'like', '%GRAB%');
        } elseif ($channel === 'GOFOOD') {
            $appSalesBase->where(function($q) {
                $q->where('payment_method', 'like', '%GOFOOD%')->orWhere('payment_method', 'like', '%GOJEK%');
            });
        } elseif ($channel === 'SHOPEEFOOD') {
            $appSalesBase->where('payment_method', 'like', '%SHOPEE%');
        } else {
            $appSalesBase->where(function ($q) {
                $q->where('payment_method', 'like', '%QRIS%')
                  ->orWhere('payment_method', 'like', '%GRAB%')
                  ->orWhere('payment_method', 'like', '%GOFOOD%')
                  ->orWhere('payment_method', 'like', '%GOJEK%')
                  ->orWhere('payment_method', 'like', '%SHOPEE%')
                  ->orWhere('payment_method', 'like', '%ECOMMERCE%')
                  ->orWhere('payment_method', 'like', '%E-COMMERCE%')
                  ->orWhere('payment_method', 'like', '%TIKTOK%')
                  ->orWhere('payment_method', 'like', '%TOKOPEDIA%')
                  ->orWhere('payment_method', 'like', '%DELIVERY%')
                  ->orWhere('payment_method', 'like', '%ONLINE%')
                  ->orWhere('payment_method', 'like', '%APLIKASI%');
            });
        }
        $totalSales = (float) ($appSalesBase->selectRaw('COALESCE(SUM(COALESCE(total_price, subtotal, 0)), 0) as total')->value('total') ?? 0);

        $depositBase = CashTransaction::where('business_id', $businessId)
            ->where('status', 'APPROVED')
            ->whereIn('category', ['SETOR_KAS_APLIKASI', 'SETORAN_QRIS', 'SETORAN_KAS_APLIKASI', 'DEPOSIT_KAS_APLIKASI']);
        if ($outletId) {
            $depositBase->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        if ($channel !== 'ALL') {
            $depositBase->where(function($q) use ($channel) {
                $q->where('payment_method', $channel)
                  ->orWhere('name', 'like', "%{$channel}%")
                  ->orWhere('notes', 'like', "%{$channel}%");
            });
        }
        $totalDeposits = (float)$depositBase->sum('amount');
        $availableBalance = max(0, round($totalSales - $totalDeposits, 2));

        // Excess calculation (if amount > availableBalance, excess goes to Pendapatan Lain-Lain 4-41002)
        $kasAplikasiPortion = min($amount, $availableBalance);
        $excessAmount = max(0, round($amount - $kasAplikasiPortion, 2));

        // Resolve Bank info
        $bankAccount = null;
        if (!empty($validated['bank_account_id'])) {
            $bankAccount = BankAccount::where('business_id', $businessId)->find($validated['bank_account_id']);
        }
        if (!$bankAccount) {
            $bankAccount = BankAccount::where('business_id', $businessId)->where('is_primary', true)->first()
                ?: BankAccount::where('business_id', $businessId)->first();
        }

        $bankTitle = $bankAccount
            ? "{$bankAccount->bank_name} ({$bankAccount->account_number} a.n {$bankAccount->account_holder})"
            : ($validated['bank_name'] ?? 'Rekening Bank Operasional');

        $transactionNo = CashTransaction::generateTransactionNo($businessId, $date);

        // Build clean notes
        $userNotes = trim((string)($validated['notes'] ?? ''));
        $depositTypeDesc = $isChecklistMode ? 'Pilihan Transaksi' : "FIFO Terawal ({$channelLabel})";
        $finalNotes = $userNotes ? "{$userNotes} (Setoran {$depositTypeDesc})" : "Pencairan Kas {$channelLabel} [{$depositTypeDesc}] ke {$bankTitle}";

        if ($adminFee > 0) {
            $finalNotes .= " [BIAYA_ADMIN:{$adminFee}] (Diterima Bersih Bank: Rp " . number_format($netAmount, 0, ',', '.') . ", Biaya Admin: Rp " . number_format($adminFee, 0, ',', '.') . ")";
        }

        if ($excessAmount > 0) {
            $finalNotes .= " [SPLIT_PENDAPATAN_LAIN:{$excessAmount}] (Termasuk selisih lebih Rp " . number_format($excessAmount, 0, ',', '.') . " masuk ke Pendapatan Lain-Lain 4-41002)";
        }

        $ct = DB::transaction(function () use (
            $businessId, $outletId, $user, $amount, $date, $bankTitle, $channelLabel,
            $channel, $validated, $transactionNo, $finalNotes, $isChecklistMode,
            $selectedTransactions
        ) {
            $record = CashTransaction::create([
                'business_id'    => $businessId,
                'outlet_id'      => $outletId,
                'transaction_no' => $transactionNo,
                'date'           => $date,
                'type'           => 'OUT',
                'activity_type'  => 'OPERATING',
                'category'       => 'SETOR_KAS_APLIKASI',
                'name'           => "Pencairan Saldo {$channelLabel} ke {$bankTitle}",
                'amount'         => $amount,
                'account'        => 'BANK_MAIN',
                'payment_method' => $channel,
                'notes'          => $finalNotes,
                'receipt_img'    => $validated['receipt_img'] ?? null,
                'status'         => 'APPROVED',
                'user_id'        => $user->id,
                'approved_by'    => $user->id,
                'approved_at'    => now(),
                'created_by'     => $user->id,
            ]);

            $settledOrderNumbers = [];

            // 1. Checklisted Transactions Mode
            if ($isChecklistMode && $selectedTransactions->isNotEmpty()) {
                foreach ($selectedTransactions as $st) {
                    $stTotal = (float)($st->total_price ?: $st->subtotal ?: 0);
                    $st->update([
                        'is_deposited'                => true,
                        'deposited_amount'            => $stTotal,
                        'deposited_at'                => now(),
                        'deposit_cash_transaction_id' => $record->id,
                    ]);
                    $settledOrderNumbers[] = $st->order_number ?: ('#' . $st->id);
                }
            } else {
                // 2. FIFO Nominal Mode (Settling earliest un-deposited transactions first)
                $fifoQuery = Transaction::where('business_id', $businessId)
                    ->where('status', 'PAID')
                    ->where('is_deposited', false);

                if ($outletId) {
                    $fifoQuery->where('outlet_id', $outletId);
                }

                if ($channel === 'QRIS') {
                    $fifoQuery->where('payment_method', 'like', '%QRIS%');
                } elseif ($channel === 'GRAB') {
                    $fifoQuery->where('payment_method', 'like', '%GRAB%');
                } elseif ($channel === 'GOFOOD') {
                    $fifoQuery->where(function($q) {
                        $q->where('payment_method', 'like', '%GOFOOD%')->orWhere('payment_method', 'like', '%GOJEK%');
                    });
                } elseif ($channel === 'SHOPEEFOOD') {
                    $fifoQuery->where('payment_method', 'like', '%SHOPEE%');
                } else {
                    $fifoQuery->where(function ($q) {
                        $q->where('payment_method', 'like', '%QRIS%')
                          ->orWhere('payment_method', 'like', '%GRAB%')
                          ->orWhere('payment_method', 'like', '%GOFOOD%')
                          ->orWhere('payment_method', 'like', '%GOJEK%')
                          ->orWhere('payment_method', 'like', '%SHOPEE%')
                          ->orWhere('payment_method', 'like', '%ECOMMERCE%')
                          ->orWhere('payment_method', 'like', '%E-COMMERCE%')
                          ->orWhere('payment_method', 'like', '%TIKTOK%')
                          ->orWhere('payment_method', 'like', '%TOKOPEDIA%')
                          ->orWhere('payment_method', 'like', '%DELIVERY%')
                          ->orWhere('payment_method', 'like', '%ONLINE%')
                          ->orWhere('payment_method', 'like', '%APLIKASI%');
                    });
                }

                // Order FIFO: Earliest date & lowest id first
                $unDepositedTxs = $fifoQuery->orderBy('date', 'asc')->orderBy('id', 'asc')->get();

                $remainingToDeposit = $amount;
                foreach ($unDepositedTxs as $ut) {
                    if ($remainingToDeposit <= 0) break;
                    $utTotal = (float)($ut->total_price ?: $ut->subtotal ?: 0);
                    $alreadyDep = (float)($ut->deposited_amount ?: 0);
                    $unDep = max(0, $utTotal - $alreadyDep);
                    if ($unDep <= 0) {
                        $ut->update(['is_deposited' => true]);
                        continue;
                    }

                    $allocated = min($remainingToDeposit, $unDep);
                    $newDeposited = $alreadyDep + $allocated;
                    $isFully = ($newDeposited >= ($utTotal - 0.01));

                    $ut->update([
                        'deposited_amount'            => $newDeposited,
                        'is_deposited'                => $isFully,
                        'deposited_at'                => now(),
                        'deposit_cash_transaction_id' => $record->id,
                    ]);

                    $remainingToDeposit -= $allocated;
                    $settledOrderNumbers[] = ($ut->order_number ?: ('#' . $ut->id)) . ($isFully ? '' : ' (sebagian)');
                }
            }

            // Append order numbers summary to cash transaction notes if any
            if (!empty($settledOrderNumbers)) {
                $previewOrders = implode(', ', array_slice($settledOrderNumbers, 0, 4));
                if (count($settledOrderNumbers) > 4) {
                    $previewOrders .= ' + ' . (count($settledOrderNumbers) - 4) . ' lainnya';
                }
                $tag = $isChecklistMode ? 'PILIH' : 'FIFO';
                $updatedNotes = $record->notes . " [{$tag}: " . count($settledOrderNumbers) . " order: {$previewOrders}]";
                $record->update(['notes' => $updatedNotes]);
            }

            // Automatically post double-entry journal (Debit Bank 1-11003, Credit Kas Aplikasi 1-11004, Credit Pendapatan Lain 4-41002 jika ada selisih lebih)
            AccountingService::postCashTransaction($record);

            return $record;
        });

        $msg = "Berhasil mencairkan Rp " . number_format($amount, 0, ',', '.') . " dari Kas {$channelLabel} ke {$bankTitle}.";
        if ($adminFee > 0) {
            $msg .= " (Dana bersih masuk rekening: Rp " . number_format($netAmount, 0, ',', '.') . ", Biaya admin platform: Rp " . number_format($adminFee, 0, ',', '.') . ")";
        }
        if ($excessAmount > 0) {
            $msg .= " (Selisih lebih Rp " . number_format($excessAmount, 0, ',', '.') . " dialokasikan ke Pendapatan Lain-Lain)";
        }

        return response()->json([
            'status'  => 'success',
            'message' => $msg,
            'data'    => $ct,
        ], 201);
    }

    /**
     * Upload or update QRIS Stiker image directly
     */
    public function uploadQrisImage(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        $request->validate([
            'image'           => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:5120',
            'image_base64'    => 'nullable|string',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'outlet_id'       => 'nullable|exists:outlets,id',
        ]);

        $imageUrl = null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = 'qris_' . $businessId . '_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('qris', $filename, 'public');
            $imageUrl = '/storage/' . $path;
        } elseif ($request->filled('image_base64')) {
            $base64 = $request->input('image_base64');
            // If it's a data url, store or keep as URL
            $imageUrl = $base64;
        }

        if (!$imageUrl) {
            return response()->json(['status' => 'error', 'message' => 'Tidak ada file gambar yang diunggah.'], 422);
        }

        // Attach to Bank Account or primary account
        if ($request->filled('bank_account_id')) {
            $bankAcc = BankAccount::where('business_id', $businessId)->find($request->bank_account_id);
            if ($bankAcc) {
                $bankAcc->update(['qr_image_url' => $imageUrl]);
            }
        } else {
            // Update or create primary bank account / all accounts
            $primary = BankAccount::where('business_id', $businessId)->where('is_primary', true)->first();
            if ($primary) {
                $primary->update(['qr_image_url' => $imageUrl]);
            } else {
                BankAccount::where('business_id', $businessId)->update(['qr_image_url' => $imageUrl]);
            }
        }

        return response()->json([
            'status'       => 'success',
            'message'      => 'Foto QRIS Toko berhasil diunggah & disimpan.',
            'qr_image_url' => $imageUrl,
        ]);
    }
}
