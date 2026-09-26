<?php

namespace App\Http\Controllers;

use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceivableController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        // Auto-sync POS transactions paid with QRIS or E-commerce channels
        $this->syncMerchantReceivables($user?->business_id, $outletId);

        $query = Receivable::with(['payments.receiver', 'outlet', 'creator', 'customer', 'transaction'])
            ->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        // AR Type Filter (CUSTOMER, MERCHANT_QRIS, MERCHANT_ECOMMERCE, MERCHANT)
        if ($request->filled('ar_type') && $request->ar_type !== 'ALL' && $request->ar_type !== 'all') {
            if ($request->ar_type === 'MERCHANT') {
                $query->whereIn('ar_type', ['MERCHANT_QRIS', 'MERCHANT_ECOMMERCE']);
            } else {
                $query->where('ar_type', $request->ar_type);
            }
        }

        // Settlement Status Filter (UNSETTLED, SETTLED)
        if ($request->filled('settlement_status') && $request->settlement_status !== 'ALL' && $request->settlement_status !== 'all') {
            $query->where('settlement_status', $request->settlement_status);
        }

        // Merchant Channel Filter
        if ($request->filled('merchant_channel') && $request->merchant_channel !== 'ALL' && $request->merchant_channel !== 'all') {
            $query->where('merchant_channel', $request->merchant_channel);
        }

        // Customer ID Filter
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Status Filter
        if ($request->filled('status') && $request->status !== 'ALL' && $request->status !== 'all') {
            if ($request->status === 'OVERDUE') {
                $query->where('status', '!=', 'PAID')
                      ->where('status', '!=', 'CANCELLED')
                      ->where('due_date', '<', now()->toDateString());
            } else {
                $query->where('status', $request->status);
            }
        }

        // Due Filter Preset
        if ($request->filled('due_filter')) {
            $today = now()->toDateString();
            if ($request->due_filter === 'OVERDUE') {
                $query->where('status', '!=', 'PAID')
                      ->where('status', '!=', 'CANCELLED')
                      ->where('due_date', '<', $today);
            } elseif ($request->due_filter === 'TODAY') {
                $query->where('due_date', $today);
            } elseif ($request->due_filter === 'THIS_WEEK') {
                $query->whereBetween('due_date', [$today, now()->addDays(7)->toDateString()]);
            } elseif ($request->due_filter === 'THIS_MONTH') {
                $query->whereBetween('due_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
            }
        }

        // Date Range (Issue Date or Due Date)
        if ($request->filled('from')) {
            $dateField = $request->date_by === 'due_date' ? 'due_date' : 'issue_date';
            $query->where($dateField, '>=', $request->from);
        }
        if ($request->filled('to')) {
            $dateField = $request->date_by === 'due_date' ? 'due_date' : 'issue_date';
            $query->where($dateField, '<=', $request->to);
        }

        // Search
        if ($request->filled('q')) {
            $q = trim($request->q);
            $query->where(function ($sub) use ($q) {
                $sub->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('merchant_channel', 'like', "%{$q}%")
                    ->orWhere('customer_phone', 'like', "%{$q}%")
                    ->orWhere('receivable_no', 'like', "%{$q}%")
                    ->orWhere('order_number', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%");
            });
        }

        $items = $query->get();

        // Calculate KPI Stats based on current tenant & outlet scope
        $statsQuery = Receivable::query();
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $statsQuery->where('outlet_id', $outletId);
        }
        $allTenantReceivables = $statsQuery->get();

        $today = now()->toDateString();
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $totalReceivables = $allTenantReceivables->where('status', '!=', 'CANCELLED')->sum('total_amount');
        $totalRemaining   = $allTenantReceivables->where('status', '!=', 'PAID')->where('status', '!=', 'CANCELLED')->sum('remaining_amount');
        $totalPaid        = $allTenantReceivables->sum('paid_amount');

        // Customer Kasbon breakdown
        $arCustTotal = $allTenantReceivables->where('ar_type', 'CUSTOMER')->where('status', '!=', 'CANCELLED')->sum('total_amount');
        $arCustRemaining = $allTenantReceivables->where('ar_type', 'CUSTOMER')->where('status', '!=', 'PAID')->where('status', '!=', 'CANCELLED')->sum('remaining_amount');

        // AR Merchant QRIS breakdown
        $arQrisTotal = $allTenantReceivables->where('ar_type', 'MERCHANT_QRIS')->sum('total_amount');
        $arQrisUnsettled = $allTenantReceivables->where('ar_type', 'MERCHANT_QRIS')->where('settlement_status', '!=', 'SETTLED')->sum('remaining_amount');
        $arQrisSettled = $allTenantReceivables->where('ar_type', 'MERCHANT_QRIS')->where('settlement_status', 'SETTLED')->sum('paid_amount');

        // AR Merchant E-Commerce breakdown (Grab, Gojek, Shopee, etc.)
        $arEcomTotal = $allTenantReceivables->where('ar_type', 'MERCHANT_ECOMMERCE')->sum('total_amount');
        $arEcomUnsettled = $allTenantReceivables->where('ar_type', 'MERCHANT_ECOMMERCE')->where('settlement_status', '!=', 'SETTLED')->sum('remaining_amount');
        $arEcomSettled = $allTenantReceivables->where('ar_type', 'MERCHANT_ECOMMERCE')->where('settlement_status', 'SETTLED')->sum('paid_amount');

        $totalMerchantReceivables = $arQrisTotal + $arEcomTotal;
        $totalMerchantUnsettled = $arQrisUnsettled + $arEcomUnsettled;
        $totalMerchantSettled = $arQrisSettled + $arEcomSettled;

        $overdueItems = $allTenantReceivables->filter(function ($r) use ($today) {
            $dueDate = is_string($r->due_date) ? $r->due_date : $r->due_date?->toDateString();
            return $r->status !== 'PAID' && $r->status !== 'CANCELLED' && $dueDate && $dueDate < $today;
        });
        $totalOverdue = $overdueItems->sum('remaining_amount');
        $countOverdue = $overdueItems->count();

        $paymentsThisMonthQuery = ReceivablePayment::whereBetween('payment_date', [$startOfMonth, $endOfMonth]);
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $paymentsThisMonthQuery->where('outlet_id', $outletId);
        }
        $paidThisMonth = $paymentsThisMonthQuery->sum('amount');

        $countUnpaid  = $allTenantReceivables->where('status', 'UNPAID')->count();
        $countPartial = $allTenantReceivables->where('status', 'PARTIAL')->count();
        $countPaid    = $allTenantReceivables->where('status', 'PAID')->count();
        $totalCustomers = $allTenantReceivables->where('ar_type', 'CUSTOMER')->pluck('customer_name')->map(fn($n) => strtolower(trim($n)))->unique()->count();

        return response()->json([
            'data'  => $items,
            'stats' => [
                'total_receivables'          => (float)$totalReceivables,
                'total_remaining'            => (float)$totalRemaining,
                'total_paid'                 => (float)$totalPaid,
                'total_overdue'              => (float)$totalOverdue,
                'count_overdue'              => $countOverdue,
                'paid_this_month'            => (float)$paidThisMonth,
                'count_unpaid'               => $countUnpaid,
                'count_partial'              => $countPartial,
                'count_paid'                 => $countPaid,
                'total_customers'            => $totalCustomers,
                // AR Breakdown
                'ar_customer_total'          => (float)$arCustTotal,
                'ar_customer_remaining'      => (float)$arCustRemaining,
                'ar_qris_total'              => (float)$arQrisTotal,
                'ar_qris_unsettled'          => (float)$arQrisUnsettled,
                'ar_qris_settled'            => (float)$arQrisSettled,
                'ar_ecommerce_total'         => (float)$arEcomTotal,
                'ar_ecommerce_unsettled'     => (float)$arEcomUnsettled,
                'ar_ecommerce_settled'       => (float)$arEcomSettled,
                'total_merchant_receivables' => (float)$totalMerchantReceivables,
                'total_merchant_unsettled'   => (float)$totalMerchantUnsettled,
                'total_merchant_settled'     => (float)$totalMerchantSettled,
            ]
        ]);
    }

    /**
     * Customer Debt Summary view: grouped by customer with unpaid transactions
     */
    public function byCustomer(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        $query = Receivable::with(['customer', 'outlet', 'payments'])
            ->where('ar_type', 'CUSTOMER')
            ->where('status', '!=', 'CANCELLED');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        if ($request->filled('q')) {
            $q = trim($request->q);
            $query->where(function ($sub) use ($q) {
                $sub->where('customer_name', 'like', "%{$q}%")
                    ->orWhere('customer_phone', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%");
            });
        }

        $all = $query->get();

        // Group by customer_id if present, or normalized customer_name
        $grouped = $all->groupBy(function ($item) {
            if ($item->customer_id) {
                return "ID_" . $item->customer_id;
            }
            return "NAME_" . strtolower(trim($item->customer_name));
        });

        $result = [];
        foreach ($grouped as $key => $items) {
            $first = $items->first();
            $totalKasbon = (float)$items->sum('total_amount');
            $totalPaid = (float)$items->sum('paid_amount');
            $totalRemaining = (float)$items->sum('remaining_amount');

            $unpaidItems = $items->where('remaining_amount', '>', 0)->values();

            $result[] = [
                'group_key'         => $key,
                'customer_id'       => $first->customer_id,
                'customer_name'     => $first->customer_name,
                'customer_phone'    => $first->customer_phone,
                'customer_address'  => $first->customer_address,
                'total_transactions'=> $items->count(),
                'unpaid_count'      => $unpaidItems->count(),
                'total_kasbon'      => $totalKasbon,
                'total_paid'        => $totalPaid,
                'total_remaining'   => $totalRemaining,
                'latest_issue_date' => $items->max('issue_date'),
                'oldest_due_date'   => $unpaidItems->min('due_date') ?? $items->min('due_date'),
                'has_overdue'       => $unpaidItems->contains(fn($i) => $i->is_overdue),
                'unpaid_items'      => $unpaidItems,
                'all_items'         => $items->values(),
            ];
        }

        // Sort by total_remaining DESC (highest active debt first)
        usort($result, fn($a, $b) => $b['total_remaining'] <=> $a['total_remaining']);

        return response()->json([
            'data' => $result
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'outlet_id'         => 'nullable|exists:outlets,id',
            'customer_id'       => 'nullable|exists:customers,id',
            'customer_name'     => 'required|string|max:150',
            'customer_phone'    => 'nullable|string|max:50',
            'customer_address'  => 'nullable|string',
            'issue_date'        => 'required|date',
            'due_date'          => 'required|date|after_or_equal:issue_date',
            'total_amount'      => 'required|numeric|min:1',
            'notes'             => 'nullable|string',
            'order_number'      => 'nullable|string|max:50',
            'transaction_id'    => 'nullable|exists:transactions,id',
            'ar_type'           => 'nullable|string|in:CUSTOMER,MERCHANT_QRIS,MERCHANT_ECOMMERCE',
            'merchant_channel'  => 'nullable|string|max:50',
            'mdr_rate'          => 'nullable|numeric|min:0',
            'mdr_fee'           => 'nullable|numeric|min:0',
            'net_amount'        => 'nullable|numeric|min:0',
            // Optional initial down payment
            'initial_paid'      => 'nullable|numeric|min:0',
            'payment_method'    => 'nullable|string',
            'reference_no'      => 'nullable|string',
        ]);

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = $data['outlet_id'] ?? $user?->outlet_id ?? 1;
        }

        $businessId = $user?->business_id ?? $request->business_id;
        $receivableNo = Receivable::generateReceivableNo($businessId, $data['issue_date']);

        $totalAmount = (float)$data['total_amount'];
        $initialPaid = (float)($data['initial_paid'] ?? 0);
        $remainingAmount = max(0, $totalAmount - $initialPaid);

        $status = 'UNPAID';
        if ($remainingAmount <= 0) {
            $status = 'PAID';
        } elseif ($initialPaid > 0) {
            $status = 'PARTIAL';
        }

        $receivable = DB::transaction(function () use ($data, $user, $outletId, $businessId, $receivableNo, $totalAmount, $initialPaid, $remainingAmount, $status) {
            $mdrFee = (float)($data['mdr_fee'] ?? 0);
            $netAmount = (float)($data['net_amount'] ?? ($totalAmount - $mdrFee));

            $rec = Receivable::create([
                'receivable_no'     => $receivableNo,
                'business_id'       => $businessId,
                'outlet_id'         => $outletId,
                'transaction_id'    => $data['transaction_id'] ?? null,
                'customer_id'       => $data['customer_id'] ?? null,
                'order_number'      => $data['order_number'] ?? null,
                'ar_type'           => $data['ar_type'] ?? 'CUSTOMER',
                'merchant_channel'  => $data['merchant_channel'] ?? null,
                'mdr_rate'          => (float)($data['mdr_rate'] ?? 0),
                'mdr_fee'           => $mdrFee,
                'net_amount'        => $netAmount,
                'settlement_status' => ($data['ar_type'] ?? 'CUSTOMER') === 'CUSTOMER' ? 'SETTLED' : 'UNSETTLED',
                'customer_name'     => $data['customer_name'],
                'customer_phone'    => $data['customer_phone'] ?? null,
                'customer_address'  => $data['customer_address'] ?? null,
                'issue_date'        => $data['issue_date'],
                'due_date'          => $data['due_date'],
                'total_amount'      => $totalAmount,
                'paid_amount'       => $initialPaid,
                'remaining_amount'  => $remainingAmount,
                'status'            => $status,
                'notes'             => $data['notes'] ?? null,
                'created_by'        => $user->id,
            ]);

            if ($initialPaid > 0) {
                $paymentNo = ReceivablePayment::generatePaymentNo($businessId, $data['issue_date']);
                ReceivablePayment::create([
                    'payment_no'     => $paymentNo,
                    'receivable_id'  => $rec->id,
                    'business_id'    => $businessId,
                    'outlet_id'      => $outletId,
                    'payment_date'   => $data['issue_date'],
                    'amount'         => $initialPaid,
                    'payment_method' => $data['payment_method'] ?? 'CASH',
                    'reference_no'   => $data['reference_no'] ?? null,
                    'notes'          => 'Uang Muka / Pembayaran Awal Kasbon',
                    'received_by'    => $user->id,
                ]);
            }

            return $rec;
        });

        $receivable->load(['payments.receiver', 'outlet', 'creator', 'customer']);
        return response()->json($receivable, 201);
    }

    public function show(Receivable $receivable)
    {
        $receivable->load(['payments.receiver', 'outlet', 'creator', 'updater', 'transaction', 'customer']);
        return response()->json($receivable);
    }

    public function update(Request $request, Receivable $receivable)
    {
        $data = $request->validate([
            'customer_id'      => 'nullable|exists:customers,id',
            'customer_name'    => 'sometimes|required|string|max:150',
            'customer_phone'   => 'nullable|string|max:50',
            'customer_address' => 'nullable|string',
            'due_date'         => 'sometimes|required|date',
            'notes'            => 'nullable|string',
            'status'           => 'nullable|string',
        ]);

        $receivable->update([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        $receivable->load(['payments.receiver', 'outlet', 'creator', 'updater', 'customer']);
        return response()->json($receivable);
    }

    public function destroy(Receivable $receivable)
    {
        $receivable->delete();
        return response()->json(['message' => 'Data kasbon berhasil dihapus']);
    }

    public function addPayment(Request $request, Receivable $receivable)
    {
        $data = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_date'   => 'required|date',
            'payment_method' => 'required|string|max:50',
            'reference_no'   => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
        ]);

        $amount = (float)$data['amount'];
        if ($amount > ($receivable->remaining_amount + 0.01)) {
            return response()->json([
                'message' => "Nominal pembayaran (Rp " . number_format($amount, 0, ',', '.') . ") melebihi sisa kasbon (Rp " . number_format($receivable->remaining_amount, 0, ',', '.') . ")."
            ], 422);
        }

        $user = $request->user();
        $businessId = $receivable->business_id ?? $user?->business_id;
        $paymentNo = ReceivablePayment::generatePaymentNo($businessId, $data['payment_date']);

        DB::transaction(function () use ($receivable, $data, $user, $businessId, $paymentNo, $amount) {
            ReceivablePayment::create([
                'payment_no'     => $paymentNo,
                'receivable_id'  => $receivable->id,
                'business_id'    => $businessId,
                'outlet_id'      => $receivable->outlet_id,
                'payment_date'   => $data['payment_date'],
                'amount'         => $amount,
                'payment_method' => $data['payment_method'],
                'reference_no'   => $data['reference_no'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'received_by'    => $user->id,
            ]);

            $totalPaid = (float)$receivable->payments()->sum('amount');
            $remaining = max(0, (float)$receivable->total_amount - $totalPaid);

            $newStatus = 'UNPAID';
            if ($remaining <= 0) {
                $newStatus = 'PAID';
            } elseif ($totalPaid > 0) {
                $newStatus = 'PARTIAL';
            }

            $receivable->update([
                'paid_amount'      => $totalPaid,
                'remaining_amount' => $remaining,
                'status'           => $newStatus,
                'updated_by'       => $user->id,
            ]);
        });

        $receivable->refresh()->load(['payments.receiver', 'outlet', 'creator', 'updater', 'customer']);
        return response()->json($receivable);
    }

    /**
     * Bulk Payment across multiple kasbon transactions of a customer
     */
    public function bulkPayment(Request $request)
    {
        $data = $request->validate([
            'customer_name'    => 'nullable|string',
            'customer_id'      => 'nullable|integer',
            'receivable_ids'   => 'nullable|array',
            'receivable_ids.*' => 'integer|exists:receivables,id',
            'amount'           => 'required|numeric|min:1',
            'payment_date'     => 'required|date',
            'payment_method'   => 'required|string|max:50',
            'reference_no'     => 'nullable|string|max:100',
            'notes'            => 'nullable|string',
        ]);

        $user = $request->user();
        $paymentAmount = (float)$data['amount'];

        // Find candidate unpaid receivables
        $query = Receivable::where('status', '!=', 'PAID')
            ->where('status', '!=', 'CANCELLED')
            ->where('remaining_amount', '>', 0);

        if (!empty($data['receivable_ids'])) {
            $query->whereIn('id', $data['receivable_ids']);
        } elseif (!empty($data['customer_id'])) {
            $query->where('customer_id', $data['customer_id']);
        } elseif (!empty($data['customer_name'])) {
            $query->where('customer_name', $data['customer_name']);
        } else {
            return response()->json(['message' => 'Harap tentukan pelanggan atau nota yang ingin dibayar.'], 422);
        }

        $receivables = $query->orderBy('issue_date', 'asc')->orderBy('id', 'asc')->get();

        if ($receivables->isEmpty()) {
            return response()->json(['message' => 'Tidak ditemukan tagihan kasbon aktif untuk pelanggan ini.'], 404);
        }

        $totalUnpaid = (float)$receivables->sum('remaining_amount');
        if ($paymentAmount > ($totalUnpaid + 0.01)) {
            return response()->json([
                'message' => 'Nominal pembayaran (Rp ' . number_format($paymentAmount, 0, ',', '.') . ') melebihi total kasbon aktif (Rp ' . number_format($totalUnpaid, 0, ',', '.') . ').'
            ], 422);
        }

        $remainingPayment = $paymentAmount;
        $updatedReceivables = [];

        DB::transaction(function () use ($receivables, $data, $user, &$remainingPayment, &$updatedReceivables) {
            foreach ($receivables as $rec) {
                if ($remainingPayment <= 0) break;

                $payForThis = min($remainingPayment, (float)$rec->remaining_amount);
                if ($payForThis <= 0) continue;

                $businessId = $rec->business_id ?? $user?->business_id;
                $paymentNo = ReceivablePayment::generatePaymentNo($businessId, $data['payment_date']);

                ReceivablePayment::create([
                    'payment_no'     => $paymentNo,
                    'receivable_id'  => $rec->id,
                    'business_id'    => $businessId,
                    'outlet_id'      => $rec->outlet_id,
                    'payment_date'   => $data['payment_date'],
                    'amount'         => $payForThis,
                    'payment_method' => $data['payment_method'],
                    'reference_no'   => $data['reference_no'] ?? null,
                    'notes'          => $data['notes'] ? "Pelunasan Sekaligus: {$data['notes']}" : 'Pembayaran Sekaligus Kasbon Pelanggan',
                    'received_by'    => $user->id,
                ]);

                $totalPaid = (float)$rec->payments()->sum('amount');
                $newRemaining = max(0, (float)$rec->total_amount - $totalPaid);

                $newStatus = 'UNPAID';
                if ($newRemaining <= 0) {
                    $newStatus = 'PAID';
                } elseif ($totalPaid > 0) {
                    $newStatus = 'PARTIAL';
                }

                $rec->update([
                    'paid_amount'      => $totalPaid,
                    'remaining_amount' => $newRemaining,
                    'status'           => $newStatus,
                    'updated_by'       => $user->id,
                ]);

                $remainingPayment -= $payForThis;
                $updatedReceivables[] = $rec->fresh();
            }
        });

        return response()->json([
            'message' => 'Pembayaran sekaligus berhasil dicatat.',
            'amount_paid' => $paymentAmount,
            'updated_count' => count($updatedReceivables),
            'data' => $updatedReceivables
        ]);
    }

    public function deletePayment(Receivable $receivable, ReceivablePayment $payment)
    {
        if ($payment->receivable_id !== $receivable->id) {
            return response()->json(['message' => 'Pembayaran tidak sesuai dengan kasbon terkait.'], 400);
        }

        $user = auth()->user();

        DB::transaction(function () use ($receivable, $payment, $user) {
            $payment->delete();

            $totalPaid = (float)$receivable->payments()->sum('amount');
            $remaining = max(0, (float)$receivable->total_amount - $totalPaid);

            $newStatus = 'UNPAID';
            if ($remaining <= 0) {
                $newStatus = 'PAID';
            } elseif ($totalPaid > 0) {
                $newStatus = 'PARTIAL';
            }

            $receivable->update([
                'paid_amount'      => $totalPaid,
                'remaining_amount' => $remaining,
                'status'           => $newStatus,
                'updated_by'       => $user?->id,
            ]);
        });

        $receivable->refresh()->load(['payments.receiver', 'outlet', 'creator', 'updater', 'customer']);
        return response()->json($receivable);
    }

    public function bulkImport(Request $request)
    {
        $user = $request->user();
        $businessId = $user?->business_id;
        $outletId = $user?->outlet_id ?? 1;

        $items = $request->input('items', []);
        if (empty($items) || !is_array($items)) {
            return response()->json(['message' => 'Data import kosong atau tidak valid.'], 422);
        }

        $importedCount = 0;

        DB::transaction(function () use ($items, $businessId, $outletId, $user, &$importedCount) {
            foreach ($items as $idx => $row) {
                if (empty($row['customer_name'])) continue;

                $custName = trim($row['customer_name']);
                $lowerCust = strtolower($custName);

                // Filter out accidental header / banner rows
                if (
                    str_starts_with($custName, '===') ||
                    str_contains($lowerCust, 'template import') ||
                    str_contains($lowerCust, 'petunjuk') ||
                    str_contains($lowerCust, 'daftar tagihan') ||
                    in_array($lowerCust, ['nama pelanggan', 'nama pelanggan*', 'nama debitur', 'nomor hp', 'total tagihan'])
                ) {
                    continue;
                }

                $custPhone = !empty($row['customer_phone']) ? trim($row['customer_phone']) : null;
                $custAddr = !empty($row['customer_address']) ? trim($row['customer_address']) : null;

                // Auto-create or find Customer
                $customer = null;
                if ($custPhone) {
                    $customer = \App\Models\Customer::firstOrCreate(
                        ['business_id' => $businessId, 'phone' => $custPhone],
                        ['name' => $custName, 'address' => $custAddr]
                    );
                } else {
                    $customer = \App\Models\Customer::firstOrCreate(
                        ['business_id' => $businessId, 'name' => $custName],
                        ['phone' => $custPhone, 'address' => $custAddr]
                    );
                }

                $totalAmount = (float)($row['total_amount'] ?? 0);
                if ($totalAmount <= 0) continue;

                $initialPaid = (float)($row['initial_paid'] ?? 0);
                $remaining = max(0, $totalAmount - $initialPaid);
                $status = ($remaining <= 0) ? 'PAID' : (($initialPaid > 0) ? 'PARTIAL' : 'UNPAID');

                $issueDate = !empty($row['issue_date']) ? $row['issue_date'] : now()->toDateString();
                $dueDate = !empty($row['due_date']) ? $row['due_date'] : now()->addDays(7)->toDateString();

                $recNo = Receivable::generateReceivableNo($businessId, $issueDate);

                $rec = Receivable::create([
                    'receivable_no'    => $recNo,
                    'business_id'      => $businessId,
                    'outlet_id'        => $outletId,
                    'customer_id'      => $customer?->id,
                    'customer_name'    => $custName,
                    'customer_phone'   => $custPhone,
                    'customer_address' => $custAddr,
                    'issue_date'       => $issueDate,
                    'due_date'         => $dueDate,
                    'total_amount'     => $totalAmount,
                    'paid_amount'      => $initialPaid,
                    'remaining_amount' => $remaining,
                    'status'           => $status,
                    'notes'            => $row['notes'] ?? 'Imported from Excel',
                    'created_by'       => $user?->id,
                ]);

                if ($initialPaid > 0) {
                    \App\Models\ReceivablePayment::create([
                        'payment_no'     => \App\Models\ReceivablePayment::generatePaymentNo($businessId, $issueDate),
                        'receivable_id'  => $rec->id,
                        'business_id'    => $businessId,
                        'outlet_id'      => $outletId,
                        'payment_date'   => $issueDate,
                        'amount'         => $initialPaid,
                        'payment_method' => 'CASH',
                        'notes'          => 'Uang Muka / DP Awal (Import Excel)',
                        'received_by'    => $user?->id,
                    ]);
                }

                $importedCount++;
            }
        });

        return response()->json([
            'message' => "Berhasil meng-import {$importedCount} data kasbon/piutang dari file Excel.",
            'imported_count' => $importedCount,
        ]);
    }

    /**
     * Merchant Settlement Summary view: grouped by merchant channel (QRIS, GrabFood, GoFood, etc.)
     */
    public function byMerchant(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        $this->syncMerchantReceivables($user?->business_id, $outletId);

        $query = Receivable::with(['outlet', 'payments.receiver'])
            ->whereIn('ar_type', ['MERCHANT_QRIS', 'MERCHANT_ECOMMERCE'])
            ->where('status', '!=', 'CANCELLED')
            ->orderBy('issue_date', 'desc');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        if ($request->filled('channel')) {
            $query->where('merchant_channel', $request->channel);
        }

        if ($request->filled('ar_type') && $request->ar_type !== 'ALL' && $request->ar_type !== 'all') {
            $query->where('ar_type', $request->ar_type);
        }

        if ($request->filled('settlement_status') && $request->settlement_status !== 'ALL' && $request->settlement_status !== 'all') {
            $query->where('settlement_status', $request->settlement_status);
        }

        if ($request->filled('q')) {
            $q = trim($request->q);
            $query->where(function ($sub) use ($q) {
                $sub->where('merchant_channel', 'like', "%{$q}%")
                    ->orWhere('order_number', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%");
            });
        }

        if ($request->filled('from')) {
            $query->where('issue_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('issue_date', '<=', $request->to);
        }

        $all = $query->get();

        $grouped = $all->groupBy(function ($item) {
            return strtoupper(trim($item->merchant_channel ?: 'OTHER'));
        });

        $channels = [];
        foreach ($grouped as $channelKey => $channelItems) {
            $first = $channelItems->first();
            $totalGross = (float)$channelItems->sum('total_amount');
            $totalMdr = (float)$channelItems->sum('mdr_fee');
            $totalNet = (float)$channelItems->sum('net_amount');
            $totalSettled = (float)$channelItems->where('settlement_status', 'SETTLED')->sum('paid_amount');
            $totalUnsettled = (float)$channelItems->where('settlement_status', '!=', 'SETTLED')->sum('remaining_amount');

            $channels[] = [
                'channel_key'     => $channelKey,
                'channel_name'    => $first->merchant_channel ?: $channelKey,
                'ar_type'         => $first->ar_type,
                'total_orders'    => $channelItems->count(),
                'total_gross'     => $totalGross,
                'total_mdr'       => $totalMdr,
                'total_net'       => $totalNet,
                'total_settled'   => $totalSettled,
                'total_unsettled' => $totalUnsettled,
                'unsettled_count' => $channelItems->where('settlement_status', '!=', 'SETTLED')->count(),
                'items'           => $channelItems->values(),
            ];
        }

        return response()->json([
            'data'     => $channels,
            'all_rows' => $all,
            'summary'  => [
                'total_channels'    => count($channels),
                'total_orders'      => $all->count(),
                'total_gross'       => (float)$all->sum('total_amount'),
                'total_mdr'         => (float)$all->sum('mdr_fee'),
                'total_net'         => (float)$all->sum('net_amount'),
                'total_unsettled'   => (float)$all->where('settlement_status', '!=', 'SETTLED')->sum('remaining_amount'),
                'total_settled'     => (float)$all->where('settlement_status', 'SETTLED')->sum('paid_amount'),
                'unsettled_count'   => $all->where('settlement_status', '!=', 'SETTLED')->count(),
                'settled_count'     => $all->where('settlement_status', 'SETTLED')->count(),
                'unsettled_amount'  => (float)$all->where('settlement_status', '!=', 'SETTLED')->sum('net_amount'),
                'settled_amount'    => (float)$all->where('settlement_status', 'SETTLED')->sum('net_amount'),
            ]
        ]);
    }

    /**
     * Settle single AR Merchant record to Bank
     */
    public function settleMerchant(Request $request, $id)
    {
        $request->validate([
            'settlement_bank' => 'nullable|string|max:100',
            'settlement_ref'  => 'nullable|string|max:100',
            'settled_at'      => 'nullable|date',
            'net_amount'      => 'nullable|numeric|min:0',
        ]);

        $receivable = Receivable::findOrFail($id);
        $settledDate = $request->settled_at ?: now()->toDateString();
        $netAmount = $request->has('net_amount') ? (float)$request->net_amount : ((float)$receivable->net_amount > 0 ? (float)$receivable->net_amount : (float)$receivable->total_amount);

        $receivable->update([
            'status'            => 'PAID',
            'settlement_status' => 'SETTLED',
            'paid_amount'       => $netAmount,
            'remaining_amount'  => 0,
            'settled_at'        => $settledDate,
            'settlement_bank'   => $request->settlement_bank ?: 'Rekening Bank Utama',
            'settlement_ref'    => $request->settlement_ref,
        ]);

        // Record a ReceivablePayment entry for settlement tracking
        ReceivablePayment::create([
            'payment_no'     => ReceivablePayment::generatePaymentNo($receivable->business_id, $settledDate),
            'receivable_id'  => $receivable->id,
            'business_id'    => $receivable->business_id,
            'outlet_id'      => $receivable->outlet_id,
            'payment_date'   => $settledDate,
            'amount'         => $netAmount,
            'payment_method' => 'TRANSFER',
            'reference_no'   => $request->settlement_ref ?: 'Settlement Payout',
            'notes'          => "Pencairan Piutang Merchant {$receivable->merchant_channel} ke {$receivable->settlement_bank}",
            'received_by'    => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pencairan AR Merchant berhasil dicatat ke rekening bank!',
            'data'    => $receivable->load(['payments.receiver', 'outlet'])
        ]);
    }

    /**
     * Settle multiple selected AR Merchant records to Bank (Bulk Settlement)
     */
    public function bulkSettleMerchant(Request $request)
    {
        $request->validate([
            'ids'             => 'required|array|min:1',
            'settlement_bank' => 'nullable|string|max:100',
            'settlement_ref'  => 'nullable|string|max:100',
            'settled_at'      => 'nullable|date',
        ]);

        $settledDate = $request->settled_at ?: now()->toDateString();
        $settledBank = $request->settlement_bank ?: 'Rekening Bank Utama';
        $user = $request->user();

        $receivables = Receivable::whereIn('id', $request->ids)->get();
        $count = 0;
        $totalCair = 0.0;

        DB::transaction(function () use ($receivables, $settledDate, $settledBank, $request, $user, &$count, &$totalCair) {
            foreach ($receivables as $r) {
                if ($r->settlement_status === 'SETTLED') continue;

                $net = (float)$r->net_amount > 0 ? (float)$r->net_amount : (float)$r->total_amount;
                $r->update([
                    'status'            => 'PAID',
                    'settlement_status' => 'SETTLED',
                    'paid_amount'       => $net,
                    'remaining_amount'  => 0,
                    'settled_at'        => $settledDate,
                    'settlement_bank'   => $settledBank,
                    'settlement_ref'    => $request->settlement_ref,
                ]);

                ReceivablePayment::create([
                    'payment_no'     => ReceivablePayment::generatePaymentNo($r->business_id, $settledDate),
                    'receivable_id'  => $r->id,
                    'business_id'    => $r->business_id,
                    'outlet_id'      => $r->outlet_id,
                    'payment_date'   => $settledDate,
                    'amount'         => $net,
                    'payment_method' => 'TRANSFER',
                    'reference_no'   => $request->settlement_ref ?: 'Bulk Settlement Payout',
                    'notes'          => "Pencairan Masal Piutang {$r->merchant_channel} ke {$settledBank}",
                    'received_by'    => $user->id,
                ]);

                $count++;
                $totalCair += $net;
            }
        });

        return response()->json([
            'success'     => true,
            'message'     => "Berhasil mencairkan {$count} transaksi AR Merchant total Rp " . number_format($totalCair, 0, ',', '.') . " ke {$settledBank}!",
            'count'       => $count,
            'total_cair'  => $totalCair,
        ]);
    }

    /**
     * Auto-synchronize POS transactions paid with QRIS or Delivery/E-Commerce to AR Merchant
     */
    public function syncMerchantReceivables(?int $businessId, $outletId = null): void
    {
        if (!$businessId) return;

        $query = \App\Models\Transaction::where('business_id', $businessId)
            ->where('status', 'PAID')
            ->whereNotNull('order_number')
            ->where(function ($q) {
                $q->where('payment_method', 'like', '%QRIS%')
                  ->orWhere('payment_method', 'like', '%GRAB%')
                  ->orWhere('payment_method', 'like', '%GOFOOD%')
                  ->orWhere('payment_method', 'like', '%SHOPEE%')
                  ->orWhere('payment_method', 'like', '%ECOMMERCE%')
                  ->orWhere('payment_method', 'like', '%TIKTOK%')
                  ->orWhere('payment_method', 'like', '%TOKOPEDIA%')
                  ->orWhere('payment_method', 'like', '%DELIVERY%')
                  ->orWhere('payment_method', 'like', '%ONLINE%');
            });

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        $existingOrderNumbers = Receivable::where('business_id', $businessId)
            ->whereIn('ar_type', ['MERCHANT_QRIS', 'MERCHANT_ECOMMERCE'])
            ->whereNotNull('order_number')
            ->pluck('order_number')
            ->all();

        $existingSet = array_flip($existingOrderNumbers);

        $trxs = $query->get();
        if ($trxs->isEmpty()) return;

        $grouped = $trxs->groupBy('order_number');

        foreach ($grouped as $orderNo => $orderTrxs) {
            if (isset($existingSet[$orderNo])) continue;

            $first = $orderTrxs->first();
            $orderTotal = (float)$orderTrxs->sum('subtotal');
            if ($orderTotal <= 0) continue;

            $pmUpper = strtoupper($first->payment_method ?: 'QRIS');
            $isQris = str_contains($pmUpper, 'QRIS');
            $arType = $isQris ? 'MERCHANT_QRIS' : 'MERCHANT_ECOMMERCE';
            $channel = $isQris ? 'QRIS' : ($pmUpper ?: 'E-COMMERCE');

            $mdrRate = $isQris ? 0.7 : 20.0;
            $mdrFee = round(($orderTotal * $mdrRate) / 100, 2);
            $net = round($orderTotal - $mdrFee, 2);

            Receivable::create([
                'receivable_no'     => Receivable::generateReceivableNo($businessId, $first->date ?: now()->toDateString()),
                'business_id'       => $businessId,
                'outlet_id'         => $first->outlet_id,
                'transaction_id'    => $first->id,
                'customer_id'       => $first->customer_id,
                'ar_type'           => $arType,
                'merchant_channel'  => $channel,
                'order_number'      => $orderNo,
                'customer_name'     => "AR Merchant - {$channel}",
                'customer_phone'    => null,
                'customer_address'  => null,
                'issue_date'        => $first->date ?: now()->toDateString(),
                'due_date'          => $first->date ?: now()->toDateString(),
                'total_amount'      => $orderTotal,
                'paid_amount'       => 0,
                'remaining_amount'  => $net,
                'mdr_rate'          => $mdrRate,
                'mdr_fee'           => $mdrFee,
                'net_amount'        => $net,
                'status'            => 'UNPAID',
                'settlement_status' => 'UNSETTLED',
                'notes'             => "Piutang Merchant {$channel} — Order #{$orderNo}",
                'created_by'        => $first->user_id,
            ]);
        }
    }
}

