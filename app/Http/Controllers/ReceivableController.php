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

        $query = Receivable::with(['payments.receiver', 'outlet', 'creator', 'customer', 'transaction'])
            ->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
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
        $totalCustomers = $allTenantReceivables->pluck('customer_name')->map(fn($n) => strtolower(trim($n)))->unique()->count();

        return response()->json([
            'data'  => $items,
            'stats' => [
                'total_receivables' => (float)$totalReceivables,
                'total_remaining'   => (float)$totalRemaining,
                'total_paid'        => (float)$totalPaid,
                'total_overdue'     => (float)$totalOverdue,
                'count_overdue'     => $countOverdue,
                'paid_this_month'   => (float)$paidThisMonth,
                'count_unpaid'      => $countUnpaid,
                'count_partial'     => $countPartial,
                'count_paid'        => $countPaid,
                'total_customers'   => $totalCustomers,
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
            $rec = Receivable::create([
                'receivable_no'    => $receivableNo,
                'business_id'      => $businessId,
                'outlet_id'        => $outletId,
                'transaction_id'   => $data['transaction_id'] ?? null,
                'customer_id'      => $data['customer_id'] ?? null,
                'order_number'     => $data['order_number'] ?? null,
                'customer_name'    => $data['customer_name'],
                'customer_phone'   => $data['customer_phone'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'issue_date'       => $data['issue_date'],
                'due_date'         => $data['due_date'],
                'total_amount'     => $totalAmount,
                'paid_amount'      => $initialPaid,
                'remaining_amount' => $remainingAmount,
                'status'           => $status,
                'notes'            => $data['notes'] ?? null,
                'created_by'       => $user->id,
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
}
