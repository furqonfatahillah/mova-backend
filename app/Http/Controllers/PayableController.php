<?php

namespace App\Http\Controllers;

use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\Supplier;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayableController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $user?->business_id;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        $query = Payable::with(['payments.payer', 'outlet', 'creator', 'ingredient', 'stockMovement', 'supplier'])
            ->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        // Supplier Filter
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        } elseif ($request->filled('supplier_name')) {
            $query->where('supplier_name', 'like', "%{$request->supplier_name}%");
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
                $sub->where('supplier_name', 'like', "%{$q}%")
                    ->orWhere('supplier_phone', 'like', "%{$q}%")
                    ->orWhere('payable_no', 'like', "%{$q}%")
                    ->orWhere('purchase_no', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%");
            });
        }

        $items = $query->get();

        // Calculate KPI Stats based on current tenant & outlet scope
        $statsQuery = Payable::query();
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $statsQuery->where('outlet_id', $outletId);
        }
        $allTenantPayables = $statsQuery->get();

        $today = now()->toDateString();
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $totalPayables = $allTenantPayables->where('status', '!=', 'CANCELLED')->sum('total_amount');
        $totalRemaining = $allTenantPayables->where('status', '!=', 'PAID')->where('status', '!=', 'CANCELLED')->sum('remaining_amount');
        $totalPaid      = $allTenantPayables->sum('paid_amount');

        $overdueItems = $allTenantPayables->filter(function ($r) use ($today) {
            $dueDate = is_string($r->due_date) ? $r->due_date : $r->due_date?->toDateString();
            return $r->status !== 'PAID' && $r->status !== 'CANCELLED' && $dueDate && $dueDate < $today;
        });
        $totalOverdue = $overdueItems->sum('remaining_amount');
        $countOverdue = $overdueItems->count();

        $paymentsThisMonthQuery = PayablePayment::whereBetween('payment_date', [$startOfMonth, $endOfMonth]);
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $paymentsThisMonthQuery->where('outlet_id', $outletId);
        }
        $paidThisMonth = $paymentsThisMonthQuery->sum('amount');

        $countUnpaid   = $allTenantPayables->where('status', 'UNPAID')->count();
        $countPartial  = $allTenantPayables->where('status', 'PARTIAL')->count();
        $countPaid     = $allTenantPayables->where('status', 'PAID')->count();
        $totalSuppliers = $allTenantPayables->pluck('supplier_name')->map(fn($n) => strtolower(trim($n)))->unique()->count();

        return response()->json([
            'data'  => $items,
            'stats' => [
                'total_payables'  => (float)$totalPayables,
                'total_remaining' => (float)$totalRemaining,
                'total_paid'      => (float)$totalPaid,
                'total_overdue'   => (float)$totalOverdue,
                'count_overdue'   => (int)$countOverdue,
                'paid_this_month' => (float)$paidThisMonth,
                'count_unpaid'    => (int)$countUnpaid,
                'count_partial'   => (int)$countPartial,
                'count_paid'      => (int)$countPaid,
                'total_suppliers' => (int)$totalSuppliers,
            ],
        ]);
    }

    public function show(Payable $payable)
    {
        $payable->load(['payments.payer', 'outlet', 'creator', 'updater', 'ingredient', 'stockMovement', 'supplier']);
        return response()->json($payable);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'outlet_id'         => 'nullable|exists:outlets,id',
            'supplier_id'       => 'nullable|exists:suppliers,id',
            'supplier_name'     => 'required|string|max:150',
            'supplier_phone'    => 'nullable|string|max:50',
            'supplier_address'  => 'nullable|string',
            'purchase_no'       => 'nullable|string|max:100',
            'ingredient_id'     => 'nullable|exists:ingredients,id',
            'stock_movement_id' => 'nullable|exists:stock_movements,id',
            'issue_date'        => 'required|date',
            'due_date'          => 'required|date',
            'total_amount'      => 'required|numeric|min:1',
            'initial_paid'      => 'nullable|numeric|min:0',
            'payment_method'    => 'nullable|string|max:50',
            'reference_no'      => 'nullable|string|max:100',
            'notes'             => 'nullable|string',
        ]);

        $user = $request->user();
        $businessId = $user?->business_id;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($data['outlet_id'] ?? $user?->outlet_id ?? Outlet::where('business_id', $businessId)->value('id'));

        $totalAmount  = (float)$data['total_amount'];
        $initialPaid  = min((float)($data['initial_paid'] ?? 0), $totalAmount);
        $remainingAmount = max(0, $totalAmount - $initialPaid);

        $status = 'UNPAID';
        if ($remainingAmount <= 0) {
            $status = 'PAID';
        } elseif ($initialPaid > 0) {
            $status = 'PARTIAL';
        }

        $payable = DB::transaction(function () use ($data, $user, $businessId, $outletId, $totalAmount, $initialPaid, $remainingAmount, $status) {
            // Auto find or create Supplier
            $supplierId = $data['supplier_id'] ?? null;
            if (!$supplierId && !empty($data['supplier_name'])) {
                $supp = Supplier::firstOrCreate(
                    ['business_id' => $businessId, 'name' => trim($data['supplier_name'])],
                    ['phone' => $data['supplier_phone'] ?? null, 'address' => $data['supplier_address'] ?? null]
                );
                $supplierId = $supp->id;
            }

            $payableNo = Payable::generatePayableNo($businessId, $data['issue_date']);
            $purchaseNo = !empty($data['purchase_no']) ? trim($data['purchase_no']) : ('PO-' . date('Ymd', strtotime($data['issue_date'])) . '-' . rand(1000, 9999));

            $pay = Payable::create([
                'payable_no'        => $payableNo,
                'purchase_no'       => $purchaseNo,
                'business_id'       => $businessId,
                'outlet_id'         => $outletId,
                'supplier_id'       => $supplierId,
                'supplier_name'     => trim($data['supplier_name']),
                'supplier_phone'    => $data['supplier_phone'] ?? null,
                'supplier_address'  => $data['supplier_address'] ?? null,
                'stock_movement_id' => $data['stock_movement_id'] ?? null,
                'ingredient_id'     => $data['ingredient_id'] ?? null,
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
                $paymentNo = PayablePayment::generatePaymentNo($businessId, $data['issue_date']);
                PayablePayment::create([
                    'payment_no'     => $paymentNo,
                    'payable_id'     => $pay->id,
                    'business_id'    => $businessId,
                    'outlet_id'      => $outletId,
                    'payment_date'   => $data['issue_date'],
                    'amount'         => $initialPaid,
                    'payment_method' => $data['payment_method'] ?? 'CASH',
                    'reference_no'   => $data['reference_no'] ?? null,
                    'notes'          => 'Uang Muka / Pembayaran Awal Hutang Supplier',
                    'paid_by'        => $user->id,
                ]);
            }

            return $pay;
        });

        $payable->load(['payments.payer', 'outlet', 'creator', 'ingredient', 'supplier']);
        return response()->json($payable, 201);
    }

    public function update(Request $request, Payable $payable)
    {
        $data = $request->validate([
            'supplier_name'    => 'sometimes|required|string|max:150',
            'supplier_phone'   => 'nullable|string|max:50',
            'supplier_address' => 'nullable|string',
            'purchase_no'      => 'nullable|string|max:100',
            'due_date'         => 'sometimes|required|date',
            'notes'            => 'nullable|string',
            'status'           => 'nullable|string',
        ]);

        $payable->update([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        $payable->load(['payments.payer', 'outlet', 'creator', 'updater', 'ingredient', 'supplier']);
        return response()->json($payable);
    }

    public function destroy(Payable $payable)
    {
        $payable->delete();
        return response()->json(['message' => 'Data hutang supplier berhasil dihapus']);
    }

    public function addPayment(Request $request, Payable $payable)
    {
        $data = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_date'   => 'required|date',
            'payment_method' => 'required|string|max:50',
            'reference_no'   => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
        ]);

        $amount = (float)$data['amount'];
        if ($amount > ($payable->remaining_amount + 0.01)) {
            return response()->json([
                'message' => "Nominal pembayaran (Rp " . number_format($amount, 0, ',', '.') . ") melebihi sisa hutang (Rp " . number_format($payable->remaining_amount, 0, ',', '.') . ")."
            ], 422);
        }

        $user = $request->user();
        $businessId = $payable->business_id ?? $user?->business_id;
        $paymentNo = PayablePayment::generatePaymentNo($businessId, $data['payment_date']);

        DB::transaction(function () use ($payable, $data, $user, $businessId, $paymentNo, $amount) {
            PayablePayment::create([
                'payment_no'     => $paymentNo,
                'payable_id'     => $payable->id,
                'business_id'    => $businessId,
                'outlet_id'      => $payable->outlet_id,
                'payment_date'   => $data['payment_date'],
                'amount'         => $amount,
                'payment_method' => $data['payment_method'],
                'reference_no'   => $data['reference_no'] ?? null,
                'notes'          => $data['notes'] ?? 'Cicilan / Pelunasan Hutang Supplier',
                'paid_by'        => $user->id,
            ]);

            $newPaid = (float)$payable->paid_amount + $amount;
            $newRemaining = max(0, (float)$payable->total_amount - $newPaid);
            $newStatus = ($newRemaining <= 0) ? 'PAID' : 'PARTIAL';

            $payable->update([
                'paid_amount'      => $newPaid,
                'remaining_amount' => $newRemaining,
                'status'           => $newStatus,
                'updated_by'       => $user->id,
            ]);
        });

        $payable->refresh()->load(['payments.payer', 'outlet', 'creator', 'updater', 'ingredient', 'supplier']);
        return response()->json($payable);
    }

    /**
     * Hapus riwayat pembayaran hutang (Void payment)
     */
    public function deletePayment(Payable $payable, PayablePayment $payment)
    {
        if ($payment->payable_id !== $payable->id) {
            return response()->json(['message' => 'Data pembayaran tidak sesuai dengan nota hutang terkait.'], 400);
        }

        $user = auth()->user();

        DB::transaction(function () use ($payable, $payment, $user) {
            $payment->delete();

            $totalPaid = (float)$payable->payments()->sum('amount');
            $remaining = max(0, (float)$payable->total_amount - $totalPaid);

            $newStatus = 'UNPAID';
            if ($remaining <= 0) {
                $newStatus = 'PAID';
            } elseif ($totalPaid > 0) {
                $newStatus = 'PARTIAL';
            }

            $payable->update([
                'paid_amount'      => $totalPaid,
                'remaining_amount' => $remaining,
                'status'           => $newStatus,
                'updated_by'       => $user?->id,
            ]);
        });

        $payable->refresh()->load(['payments.payer', 'outlet', 'creator', 'updater', 'ingredient', 'supplier']);
        return response()->json($payable);
    }

    /**
     * Bulk Payment across multiple payables of a supplier (Bayar Sekaligus Semua Hutang)
     */
    public function bulkPayment(Request $request)
    {
        $data = $request->validate([
            'supplier_name'  => 'nullable|string',
            'supplier_id'    => 'nullable|integer',
            'payable_ids'    => 'nullable|array',
            'payable_ids.*'  => 'integer|exists:payables,id',
            'amount'         => 'required|numeric|min:1',
            'payment_date'   => 'required|date',
            'payment_method' => 'required|string|max:50',
            'reference_no'   => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
        ]);

        $user = $request->user();
        $paymentAmount = (float)$data['amount'];

        $query = Payable::where('status', '!=', 'PAID')
            ->where('status', '!=', 'CANCELLED')
            ->where('remaining_amount', '>', 0);

        if (!empty($data['payable_ids'])) {
            $query->whereIn('id', $data['payable_ids']);
        } elseif (!empty($data['supplier_id'])) {
            $query->where('supplier_id', $data['supplier_id']);
        } elseif (!empty($data['supplier_name'])) {
            $query->where('supplier_name', $data['supplier_name']);
        } else {
            return response()->json(['message' => 'Harap tentukan supplier atau nota hutang yang ingin dibayar.'], 422);
        }

        $payables = $query->orderBy('issue_date', 'asc')->orderBy('id', 'asc')->get();

        if ($payables->isEmpty()) {
            return response()->json(['message' => 'Tidak ditemukan tagihan hutang aktif untuk supplier ini.'], 404);
        }

        $totalUnpaid = (float)$payables->sum('remaining_amount');
        if ($paymentAmount > ($totalUnpaid + 0.01)) {
            return response()->json([
                'message' => 'Nominal pembayaran (Rp ' . number_format($paymentAmount, 0, ',', '.') . ') melebihi total hutang aktif (Rp ' . number_format($totalUnpaid, 0, ',', '.') . ').'
            ], 422);
        }

        $remainingPayment = $paymentAmount;
        $updatedPayables = [];

        DB::transaction(function () use ($payables, $data, $user, &$remainingPayment, &$updatedPayables) {
            foreach ($payables as $pay) {
                if ($remainingPayment <= 0) break;

                $payForThis = min($remainingPayment, (float)$pay->remaining_amount);
                if ($payForThis <= 0) continue;

                $businessId = $pay->business_id ?? $user?->business_id;
                $paymentNo = PayablePayment::generatePaymentNo($businessId, $data['payment_date']);

                PayablePayment::create([
                    'payment_no'     => $paymentNo,
                    'payable_id'     => $pay->id,
                    'business_id'    => $businessId,
                    'outlet_id'      => $pay->outlet_id,
                    'payment_date'   => $data['payment_date'],
                    'amount'         => $payForThis,
                    'payment_method' => $data['payment_method'],
                    'reference_no'   => $data['reference_no'] ?? null,
                    'notes'          => $data['notes'] ? "Pelunasan Sekaligus: {$data['notes']}" : 'Pembayaran Sekaligus Hutang Supplier',
                    'paid_by'        => $user->id,
                ]);

                $newPaid = (float)$pay->paid_amount + $payForThis;
                $newRemaining = max(0, (float)$pay->total_amount - $newPaid);
                $newStatus = ($newRemaining <= 0) ? 'PAID' : 'PARTIAL';

                $pay->update([
                    'paid_amount'      => $newPaid,
                    'remaining_amount' => $newRemaining,
                    'status'           => $newStatus,
                    'updated_by'       => $user->id,
                ]);

                $remainingPayment -= $payForThis;
                $updatedPayables[] = $pay->id;
            }
        });

        $resultList = Payable::with(['payments.payer', 'outlet', 'creator', 'ingredient', 'supplier'])
            ->whereIn('id', $updatedPayables)
            ->get();

        return response()->json([
            'message' => "Berhasil memproses pembayaran sekaligus sebesar Rp " . number_format($paymentAmount, 0, ',', '.') . " untuk " . count($updatedPayables) . " nota hutang supplier.",
            'updated_payables' => $resultList,
        ]);
    }

    /**
     * Laporan Hutang Supplier (Formatted precisely matching user's image)
     */
    public function report(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to   = $request->to   ?? now()->endOfMonth()->toDateString();

        $query = Payable::with(['payments.payer', 'creator', 'outlet', 'ingredient'])
            ->whereBetween('issue_date', [$from, $to])
            ->orderBy('issue_date', 'asc')
            ->orderBy('id', 'asc');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        if ($request->filled('supplier_name')) {
            $query->where('supplier_name', 'like', "%{$request->supplier_name}%");
        }

        if ($request->filled('status') && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        $payables = $query->get();

        // Build report rows exactly mirroring user's Excel columns:
        // No | Supplier/Tanggal | Tgl. Dibuat | Dibuat Oleh | No.Pembelian | No.Bayar | Jatuh Tempo | Hutang | Dibayar | Sisa Hutang | Total Hutang
        $reportRows = [];
        $no = 1;
        $sumHutang = 0;
        $sumDibayar = 0;
        $sumSisa = 0;

        foreach ($payables as $p) {
            $paymentNos = $p->payments->pluck('payment_no')->implode(', ');
            $reportRows[] = [
                'no'               => $no++,
                'id'               => $p->id,
                'supplier_name'    => $p->supplier_name,
                'supplier_tanggal' => "{$p->supplier_name} - " . date('d/m/Y', strtotime($p->issue_date)),
                'tgl_dibuat'       => date('Y-m-d', strtotime($p->issue_date)),
                'tgl_dibuat_fmt'   => date('d/m/Y', strtotime($p->issue_date)),
                'dibuat_oleh'      => $p->creator?->name ?? 'Admin',
                'no_pembelian'     => $p->purchase_no ?: $p->payable_no,
                'no_bayar'         => $paymentNos ?: '-',
                'jatuh_tempo'      => date('Y-m-d', strtotime($p->due_date)),
                'jatuh_tempo_fmt'  => date('d/m/Y', strtotime($p->due_date)),
                'hutang'           => (float)$p->total_amount,
                'dibayar'          => (float)$p->paid_amount,
                'sisa_hutang'      => (float)$p->remaining_amount,
                'total_hutang'     => (float)$p->total_amount,
                'status'           => $p->status,
                'status_label'     => $p->status_label,
                'ingredient_name'  => $p->ingredient?->name ?? '-',
            ];

            $sumHutang   += (float)$p->total_amount;
            $sumDibayar  += (float)$p->paid_amount;
            $sumSisa     += (float)$p->remaining_amount;
        }

        return response()->json([
            'period' => [
                'from' => $from,
                'to'   => $to,
                'from_formatted' => date('d F Y', strtotime($from)),
                'to_formatted'   => date('d F Y', strtotime($to)),
            ],
            'outlet'  => $outletId ? (Outlet::find($outletId)?->name ?? 'Semua Cabang') : 'Semua Cabang',
            'summary' => [
                'total_hutang'      => $sumHutang,
                'total_dibayar'     => $sumDibayar,
                'total_sisa_hutang' => $sumSisa,
                'count_rows'        => count($reportRows),
            ],
            'rows' => $reportRows,
        ]);
    }

    /**
     * Unique suppliers list for autocomplete
     */
    public function suppliers(Request $request)
    {
        $user = $request->user();
        $businessId = $user?->business_id;

        $dbSuppliers = Supplier::where('active', true)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->get(['id', 'name', 'phone', 'contact_person', 'address']);

        $payableSuppliers = Payable::select('supplier_name', 'supplier_phone')
            ->distinct()
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->get();

        $merged = [];
        foreach ($dbSuppliers as $s) {
            $key = strtolower(trim($s->name));
            $merged[$key] = [
                'id'             => $s->id,
                'name'           => $s->name,
                'phone'          => $s->phone,
                'contact_person' => $s->contact_person,
                'address'        => $s->address,
            ];
        }
        foreach ($payableSuppliers as $ps) {
            $key = strtolower(trim($ps->supplier_name));
            if (!isset($merged[$key]) && !empty($ps->supplier_name)) {
                $merged[$key] = [
                    'id'             => null,
                    'name'           => $ps->supplier_name,
                    'phone'          => $ps->supplier_phone,
                    'contact_person' => null,
                    'address'        => null,
                ];
            }
        }

        return response()->json(array_values($merged));
    }
}
