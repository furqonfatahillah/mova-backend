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

        $allTimeQrisSales = (float) (clone $appSalesBase)->sum('subtotal');
        $todayQrisSales = (float) (clone $appSalesBase)->where('date', $today)->sum('subtotal');
        $monthQrisSales = (float) (clone $appSalesBase)->whereBetween('date', [$thisMonthStart, $thisMonthEnd])->sum('subtotal');

        // Filtered period sales
        $appSalesQuery = clone $appSalesBase;
        if ($from) {
            $appSalesQuery->where('date', '>=', $from);
        }
        if ($to) {
            $appSalesQuery->where('date', '<=', $to);
        }
        $periodQrisSales = (float) $appSalesQuery->sum('subtotal');

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
        $bankAccounts = BankAccount::where('business_id', $businessId)
            ->where('is_active', true)
            ->where(function ($q) use ($outletId) {
                if ($outletId) {
                    $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                }
            })
            ->orderBy('is_primary', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        // 4. Primary QRIS Image URL
        $primaryAccount = $bankAccounts->firstWhere('is_primary', true) ?: $bankAccounts->first();
        $qrisImageUrl = $primaryAccount?->qr_image_url;

        // 5. Recent History of Kas Aplikasi (Both QRIS/E-Commerce In & Bank Deposits Out)
        // A. Sales In (QRIS & E-Commerce)
        $appTxQuery = Transaction::with(['outlet', 'customer', 'shift'])
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

            return [
                'id'              => 'APP-' . $t->id,
                'source_id'       => $t->id,
                'type'            => 'IN',
                'method_category' => $methodCat,
                'type_label'      => $label,
                'date'            => $t->date ? (is_string($t->date) ? substr($t->date, 0, 10) : $t->date->format('Y-m-d')) : null,
                'time'            => $t->created_at ? $t->created_at->format('H:i') : null,
                'reference_no'    => $t->order_number ?: ('TRX-' . $t->id),
                'name'            => 'Penjualan ' . ($pm ?: 'Aplikasi') . ' #' . ($t->order_number ?: $t->id) . ' (' . ($t->customer_name ?: 'Pelanggan') . ')',
                'outlet_name'     => $t->outlet?->name ?? 'Cabang',
                'amount'          => (float)$t->subtotal,
                'payment_method'  => $pm ?: 'KAS_APLIKASI',
                'account'         => 'KAS_APLIKASI',
                'status'          => 'PAID',
                'notes'           => $t->notes,
                'created_at'      => $t->created_at ? $t->created_at->toIso8601String() : null,
            ];
        });

        // B. Bank Deposits (OUT)
        $depTxQuery = CashTransaction::with(['outlet', 'user', 'approver'])
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
                'created_by'      => $ct->user?->name ?? 'Admin',
                'created_at'      => $ct->created_at ? $ct->created_at->toIso8601String() : null,
            ];
        });

        // Calculate breakdown by method
        $methods = ['QRIS', 'GRAB', 'GOFOOD', 'SHOPEEFOOD', 'OTHER'];
        $byMethodBreakdown = [];
        foreach ($methods as $mKey) {
            $matchingIn = $qrisTransactions->where('method_category', $mKey);
            $byMethodBreakdown[$mKey] = [
                'total_amount' => (float)$matchingIn->sum('amount'),
                'count'        => $matchingIn->count(),
            ];
        }
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
     * Deposit funds from Kas Aplikasi (QRIS) to Bank Account
     */
    public function deposit(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id;

        $validated = $request->validate([
            'amount'          => 'required|numeric|min:1',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'bank_name'       => 'nullable|string|max:100',
            'account_number'  => 'nullable|string|max:50',
            'account_holder'  => 'nullable|string|max:255',
            'outlet_id'       => 'nullable|exists:outlets,id',
            'date'            => 'nullable|date',
            'notes'           => 'nullable|string|max:500',
            'receipt_img'     => 'nullable|string',
        ]);

        $outletId = $validated['outlet_id'] ?? ($user->outlet_id ?: null);
        $amount = (float)$validated['amount'];
        $date = $validated['date'] ?? now()->toDateString();

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

        $ct = DB::transaction(function () use ($businessId, $outletId, $user, $amount, $date, $bankTitle, $validated, $transactionNo) {
            $record = CashTransaction::create([
                'business_id'    => $businessId,
                'outlet_id'      => $outletId,
                'transaction_no' => $transactionNo,
                'date'           => $date,
                'type'           => 'OUT',
                'activity_type'  => 'OPERATING',
                'category'       => 'SETOR_KAS_APLIKASI',
                'name'           => "Setoran Kas Aplikasi (QRIS) ke {$bankTitle}",
                'amount'         => $amount,
                'account'        => 'BANK_MAIN',
                'payment_method' => 'TRANSFER',
                'notes'          => $validated['notes'] ?? "Pencairan / Setor saldo Kas Aplikasi (QRIS) ke {$bankTitle}",
                'receipt_img'    => $validated['receipt_img'] ?? null,
                'status'         => 'APPROVED',
                'user_id'        => $user->id,
                'approved_by'    => $user->id,
                'approved_at'    => now(),
                'created_by'     => $user->id,
            ]);

            // Automatically post double-entry journal (Debit Bank 1-11003, Credit Kas Aplikasi 1-11004)
            AccountingService::postCashTransaction($record);

            return $record;
        });

        return response()->json([
            'status'  => 'success',
            'message' => "Berhasil menyetor Rp " . number_format($amount, 0, ',', '.') . " dari Kas Aplikasi ke {$bankTitle}.",
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
