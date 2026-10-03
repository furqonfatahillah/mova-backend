<?php

namespace App\Http\Controllers;

use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Outlet;
use App\Models\Customer;
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

        $query = Receivable::with(['payments.receiver', 'payments.shift', 'outlet', 'creator', 'customer', 'transaction', 'shift'])
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

        // Helper to normalize phone numbers
        $normPhone = function ($phone) {
            $d = preg_replace('/[^0-9]/', '', (string)$phone);
            if (str_starts_with($d, '62')) {
                $d = '0' . substr($d, 2);
            }
            return $d;
        };

        // Group by customer_id if present, or name + phone, or normalized customer_name
        $grouped = $all->groupBy(function ($item) use ($normPhone) {
            if ($item->customer_id) {
                return "ID_" . $item->customer_id;
            }
            $p = $normPhone($item->customer_phone);
            $name = strtolower(trim((string)$item->customer_name));
            if (!empty($p) && strlen($p) >= 6) {
                return "CUST_" . $name . "_" . $p;
            }
            return "NAME_" . $name;
        });

        $result = [];
        foreach ($grouped as $key => $items) {
            $first = $items->first();
            $totalKasbon = (float)$items->sum('total_amount');
            $totalPaid = (float)$items->sum('paid_amount');
            $totalRemaining = (float)$items->sum('remaining_amount');

            $unpaidItems = $items->where('remaining_amount', '>', 0)->values();

            // Find best available non-empty phone and address
            $phone = $first->customer_phone;
            $address = $first->customer_address;
            foreach ($items as $it) {
                if (empty($phone) && !empty(trim($it->customer_phone ?? ''))) {
                    $phone = trim($it->customer_phone);
                }
                if (empty($address) && !empty(trim($it->customer_address ?? ''))) {
                    $address = trim($it->customer_address);
                }
            }

            $result[] = [
                'group_key'         => $key,
                'customer_id'       => $first->customer_id,
                'customer_name'     => $first->customer_name,
                'customer_phone'    => $phone,
                'customer_address'  => $address,
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

    /**
     * Search customer history for creating Kasbon invoice (matches name or phone).
     * Distinguishes customers with identical names by their phone numbers.
     */
    public function searchHistoryCustomers(Request $request)
    {
        $user = $request->user();
        $businessId = $user->business_id ?? null;
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? null);

        $rawQ = trim($request->get('q', ''));
        $numericQ = preg_replace('/[^0-9]/', '', $rawQ);

        // Fetch customer receivables
        $recQuery = Receivable::query()
            ->where('ar_type', 'CUSTOMER')
            ->where('status', '!=', 'CANCELLED');

        if ($businessId) {
            $recQuery->where('business_id', $businessId);
        }
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $recQuery->where('outlet_id', $outletId);
        }

        if (!empty($rawQ)) {
            $s = '%' . $rawQ . '%';
            $recQuery->where(function ($sub) use ($s, $numericQ) {
                $sub->where('customer_name', 'like', $s)
                    ->orWhere('customer_phone', 'like', $s)
                    ->orWhere('customer_address', 'like', $s);
                if (!empty($numericQ) && strlen($numericQ) >= 3) {
                    $sub->orWhere(DB::raw("REPLACE(REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', ''), '.', '')"), 'like', '%' . $numericQ . '%');
                }
            });
        }

        $receivables = $recQuery->orderBy('issue_date', 'desc')->orderBy('id', 'desc')->get();

        // Helper to normalize phone numbers
        $normPhone = function ($phone) {
            $d = preg_replace('/[^0-9]/', '', (string)$phone);
            if (str_starts_with($d, '62')) {
                $d = '0' . substr($d, 2);
            }
            return $d;
        };

        // Group receivables by customer_id OR name + phone
        $groups = $receivables->groupBy(function ($item) use ($normPhone) {
            if ($item->customer_id) {
                return "ID_" . $item->customer_id;
            }
            $p = $normPhone($item->customer_phone);
            $name = strtolower(trim((string)$item->customer_name));
            if (!empty($p) && strlen($p) >= 6) {
                return "CUST_" . $name . "_" . $p;
            }
            return "NAME_" . $name;
        });

        $results = [];
        $matchedCustomerIds = [];

        foreach ($groups as $key => $items) {
            $first = $items->first();
            $totalKasbon = (float)$items->sum('total_amount');
            $totalPaid = (float)$items->sum('paid_amount');
            $totalRemaining = (float)$items->sum('remaining_amount');
            $unpaidItems = $items->where('remaining_amount', '>', 0);
            $unpaidCount = $unpaidItems->count();

            // Find best non-empty address and phone
            $address = null;
            $phone = $first->customer_phone;
            foreach ($items as $it) {
                if (empty($address) && !empty(trim($it->customer_address ?? ''))) {
                    $address = trim($it->customer_address);
                }
                if (empty($phone) && !empty(trim($it->customer_phone ?? ''))) {
                    $phone = trim($it->customer_phone);
                }
            }

            if ($first->customer_id) {
                $matchedCustomerIds[] = $first->customer_id;
            }

            $results[] = [
                'group_key'         => $key,
                'source'            => 'RECEIVABLE_HISTORY',
                'customer_id'       => $first->customer_id,
                'customer_name'     => $first->customer_name,
                'customer_phone'    => $phone,
                'customer_address'  => $address,
                'total_transactions'=> $items->count(),
                'unpaid_count'      => $unpaidCount,
                'total_kasbon'      => $totalKasbon,
                'total_paid'        => $totalPaid,
                'total_remaining'   => $totalRemaining,
                'latest_issue_date' => $items->max('issue_date'),
                'oldest_due_date'   => $unpaidItems->min('due_date') ?? $items->min('due_date'),
                'has_overdue'       => $unpaidItems->contains(fn($i) => $i->is_overdue),
                'member_code'       => null,
                'total_points'      => null,
            ];
        }

        // Also search registered members (Customer model)
        $custQuery = Customer::where('active', true);
        if ($businessId) {
            $custQuery->where('business_id', $businessId);
        }
        if (!empty($rawQ)) {
            $s = '%' . $rawQ . '%';
            $custQuery->where(function ($sub) use ($s, $numericQ) {
                $sub->where('name', 'like', $s)
                    ->orWhere('phone', 'like', $s)
                    ->orWhere('code', 'like', $s)
                    ->orWhere('address', 'like', $s);
                if (!empty($numericQ) && strlen($numericQ) >= 3) {
                    $sub->orWhere(DB::raw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', '')"), 'like', '%' . $numericQ . '%');
                }
            });
        }

        if (!empty($matchedCustomerIds)) {
            $custQuery->whereNotIn('id', $matchedCustomerIds);
        }

        $matchedMembers = $custQuery->limit(20)->get();

        foreach ($matchedMembers as $m) {
            $mNorm = $normPhone($m->phone);
            $alreadyLinked = false;

            // Check if phone matches any result without customer_id
            foreach ($results as &$r) {
                $rNorm = $normPhone($r['customer_phone']);
                if (!empty($mNorm) && $mNorm === $rNorm) {
                    $r['customer_id'] = $m->id;
                    $r['member_code'] = $m->code;
                    $r['total_points'] = $m->total_points;
                    $alreadyLinked = true;
                    break;
                }
            }
            unset($r);

            if (!$alreadyLinked) {
                $results[] = [
                    'group_key'         => 'MBR_' . $m->id,
                    'source'            => 'MASTER_CUSTOMER',
                    'customer_id'       => $m->id,
                    'customer_name'     => $m->name,
                    'customer_phone'    => $m->phone,
                    'customer_address'  => $m->address,
                    'total_transactions'=> 0,
                    'unpaid_count'      => 0,
                    'total_kasbon'      => 0,
                    'total_paid'        => 0,
                    'total_remaining'   => 0,
                    'latest_issue_date' => null,
                    'oldest_due_date'   => null,
                    'has_overdue'       => false,
                    'member_code'       => $m->code,
                    'total_points'      => $m->total_points,
                ];
            }
        }

        // Sort: active kasbon first, then by most recent date, then name
        usort($results, function ($a, $b) {
            if ($a['total_remaining'] > 0 && $b['total_remaining'] <= 0) return -1;
            if ($b['total_remaining'] > 0 && $a['total_remaining'] <= 0) return 1;
            if ($a['total_remaining'] != $b['total_remaining']) {
                return $b['total_remaining'] <=> $a['total_remaining'];
            }
            if ($a['latest_issue_date'] && $b['latest_issue_date']) {
                return strcmp($b['latest_issue_date'], $a['latest_issue_date']);
            }
            if ($a['latest_issue_date']) return -1;
            if ($b['latest_issue_date']) return 1;
            return strcasecmp($a['customer_name'] ?? '', $b['customer_name'] ?? '');
        });

        return response()->json(array_slice($results, 0, 40));
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

            $shiftId = $data['shift_id'] ?? \App\Models\Shift::where('status', 'OPEN')->where('outlet_id', $outletId)->orderByDesc('opened_at')->value('id');

            $rec = Receivable::create([
                'receivable_no'     => $receivableNo,
                'business_id'       => $businessId,
                'outlet_id'         => $outletId,
                'shift_id'          => $shiftId,
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
                    'shift_id'       => $shiftId,
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

        $receivable->load(['payments.receiver', 'payments.shift', 'outlet', 'creator', 'customer', 'shift']);
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
        if ($receivable->ar_type === 'MERCHANT_ECOMMERCE') {
            return response()->json([
                'message' => 'AR E-Commerce tidak dapat dibayar atau dicairkan secara manual di POS karena pencairannya diproses langsung dari aplikasi e-commerce terkait. Buku piutang e-commerce hanya berfungsi untuk rekonsiliasi / cross-check besaran transaksi.'
            ], 422);
        }

        $data = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'cash_received'  => 'nullable|numeric|min:0',
            'payment_date'   => 'required|date',
            'payment_method' => 'required|string|max:50',
            'reference_no'   => 'nullable|string|max:100',
            'notes'          => 'nullable|string',
        ]);

        $inputAmount = (float)$data['amount'];
        $cashReceived = isset($data['cash_received']) && (float)$data['cash_received'] > 0
            ? (float)$data['cash_received']
            : $inputAmount;

        $isCash = in_array(strtoupper($data['payment_method']), ['CASH', 'TUNAI']);
        $remainingAmount = (float)$receivable->remaining_amount;

        if ($isCash) {
            // If paying cash and input amount or cash received is greater than remaining kasbon
            if ($inputAmount > $remainingAmount || $cashReceived > $remainingAmount) {
                $amount = min($inputAmount, $remainingAmount);
                $change = max(0, $cashReceived - $amount);
            } else {
                $amount = $inputAmount;
                $change = max(0, $cashReceived - $amount);
            }
        } else {
            if ($inputAmount > ($remainingAmount + 0.01)) {
                return response()->json([
                    'message' => "Nominal pembayaran (Rp " . number_format($inputAmount, 0, ',', '.') . ") melebihi sisa kasbon (Rp " . number_format($remainingAmount, 0, ',', '.') . ")."
                ], 422);
            }
            $amount = $inputAmount;
            $change = 0;
        }

        $user = $request->user();
        $businessId = $receivable->business_id ?? $user?->business_id;
        $paymentNo = ReceivablePayment::generatePaymentNo($businessId, $data['payment_date']);
        $shiftId = $data['shift_id'] ?? \App\Models\Shift::where('status', 'OPEN')->where('outlet_id', $receivable->outlet_id)->orderByDesc('opened_at')->value('id') ?? $receivable->shift_id;

        DB::transaction(function () use ($receivable, $data, $user, $businessId, $paymentNo, $amount, $shiftId) {
            ReceivablePayment::create([
                'payment_no'     => $paymentNo,
                'receivable_id'  => $receivable->id,
                'business_id'    => $businessId,
                'outlet_id'      => $receivable->outlet_id,
                'shift_id'       => $shiftId,
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
        return response()->json([
            'message'        => 'Pembayaran kasbon berhasil dicatat.',
            'receivable'     => $receivable,
            'amount_paid'    => $amount,
            'cash_received'  => $cashReceived,
            'change'         => $change,
        ]);
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
            'cash_received'    => 'nullable|numeric|min:0',
            'payment_date'     => 'required|date',
            'payment_method'   => 'required|string|max:50',
            'reference_no'     => 'nullable|string|max:100',
            'notes'            => 'nullable|string',
        ]);

        $user = $request->user();
        $inputAmount = (float)$data['amount'];
        $cashReceived = isset($data['cash_received']) && (float)$data['cash_received'] > 0
            ? (float)$data['cash_received']
            : $inputAmount;

        $isCash = in_array(strtoupper($data['payment_method']), ['CASH', 'TUNAI']);

        // Find candidate unpaid receivables (exclude E-Commerce since it settles automatically via e-commerce apps)
        $query = Receivable::where('status', '!=', 'PAID')
            ->where('status', '!=', 'CANCELLED')
            ->where('ar_type', '!=', 'MERCHANT_ECOMMERCE')
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

        if ($isCash) {
            if ($inputAmount > $totalUnpaid || $cashReceived > $totalUnpaid) {
                $effectivePayAmount = min($inputAmount, $totalUnpaid);
                $change = max(0, $cashReceived - $effectivePayAmount);
            } else {
                $effectivePayAmount = $inputAmount;
                $change = max(0, $cashReceived - $effectivePayAmount);
            }
        } else {
            if ($inputAmount > ($totalUnpaid + 0.01)) {
                return response()->json([
                    'message' => 'Nominal pembayaran (Rp ' . number_format($inputAmount, 0, ',', '.') . ') melebihi total kasbon aktif (Rp ' . number_format($totalUnpaid, 0, ',', '.') . ').'
                ], 422);
            }
            $effectivePayAmount = $inputAmount;
            $change = 0;
        }

        $remainingPayment = $effectivePayAmount;
        $updatedReceivables = [];
        $firstRecOutlet = $receivables->first()?->outlet_id;
        $shiftId = $data['shift_id'] ?? \App\Models\Shift::where('status', 'OPEN')->where('outlet_id', $firstRecOutlet)->orderByDesc('opened_at')->value('id') ?? $receivables->first()?->shift_id;

        DB::transaction(function () use ($receivables, $data, $user, &$remainingPayment, &$updatedReceivables, $shiftId) {
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
                    'shift_id'       => $shiftId,
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
            'message'        => 'Pembayaran sekaligus berhasil dicatat.',
            'amount_paid'    => $effectivePayAmount,
            'cash_received'  => $cashReceived,
            'change'         => $change,
            'updated_count'  => count($updatedReceivables),
            'data'           => $updatedReceivables
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

        try {
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
        } catch (\Throwable $e) {
            try {
                \Illuminate\Support\Facades\Log::error("bulkImport Receivable error: " . $e->getMessage());
            } catch (\Throwable $logEx) {}

            return response()->json([
                'message' => 'Gagal meng-import piutang: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => "Berhasil meng-import {$importedCount} data kasbon/piutang dari file Excel.",
            'imported_count' => $importedCount,
        ]);
    }

    /**
     * Merchant Settlement Summary view: grouped by merchant channel (QRIS, GrabFood, GoFood, etc.)
    /**
     * Merchant Settlement Summary view: grouped by merchant channel (QRIS, GrabFood, GoFood, etc.)
     */
    public function byMerchant(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        $this->syncMerchantReceivables($user?->business_id, $outletId);

        $query = Receivable::with(['outlet', 'payments.receiver', 'shift.user', 'transaction.shift.user', 'transaction.user'])
            ->whereIn('ar_type', ['MERCHANT_QRIS', 'MERCHANT_ECOMMERCE'])
            ->where('status', '!=', 'CANCELLED')
            ->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc');

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
                    ->orWhere('customer_name', 'like', "%{$q}%")
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
                'total_unsettled'   => (float)$all->where('ar_type', 'MERCHANT_QRIS')->where('settlement_status', '!=', 'SETTLED')->sum('remaining_amount'),
                'total_settled'     => (float)$all->where('ar_type', 'MERCHANT_QRIS')->where('settlement_status', 'SETTLED')->sum('paid_amount'),
                'unsettled_count'   => $all->where('ar_type', 'MERCHANT_QRIS')->where('settlement_status', '!=', 'SETTLED')->count(),
                'settled_count'     => $all->where('ar_type', 'MERCHANT_QRIS')->where('settlement_status', 'SETTLED')->count(),
                'unsettled_amount'  => (float)$all->where('ar_type', 'MERCHANT_QRIS')->where('settlement_status', '!=', 'SETTLED')->sum('net_amount'),
                'settled_amount'    => (float)$all->where('ar_type', 'MERCHANT_QRIS')->where('settlement_status', 'SETTLED')->sum('net_amount'),
                'ecom_count'        => $all->where('ar_type', 'MERCHANT_ECOMMERCE')->count(),
                'ecom_gross'        => (float)$all->where('ar_type', 'MERCHANT_ECOMMERCE')->sum('total_amount'),
                'ecom_net'          => (float)$all->where('ar_type', 'MERCHANT_ECOMMERCE')->sum('net_amount'),
            ]
        ]);
    }

    /**
     * Hierarchical E-Commerce Reconciliation view:
     * Level 1: Tanggal (Date) -> Level 2: Shift -> Level 3: Individual Orders
     */
    public function ecommerceGrouped(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        $this->syncMerchantReceivables($user?->business_id, $outletId);

        $query = Receivable::with([
            'outlet',
            'shift.user',
            'transaction.shift.user',
            'transaction.user',
            'payments.receiver'
        ])
            ->where('ar_type', 'MERCHANT_ECOMMERCE')
            ->where('status', '!=', 'CANCELLED')
            ->orderBy('issue_date', 'desc')
            ->orderBy('id', 'desc');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        if ($request->filled('channel') && $request->channel !== 'ALL' && $request->channel !== 'all') {
            $query->where('merchant_channel', $request->channel);
        }

        if ($request->filled('from')) {
            $query->where('issue_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('issue_date', '<=', $request->to);
        }

        if ($request->filled('q')) {
            $q = trim($request->q);
            $query->where(function ($sub) use ($q) {
                $sub->where('merchant_channel', 'like', "%{$q}%")
                    ->orWhere('order_number', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%");
            });
        }

        $allRows = $query->get();

        // Level 1: Group by issue_date (Date)
        $dateGrouped = $allRows->groupBy(function ($item) {
            return is_string($item->issue_date) ? substr($item->issue_date, 0, 10) : ($item->issue_date?->toDateString() ?: 'NO_DATE');
        });

        $tree = [];
        foreach ($dateGrouped as $dateKey => $dateItems) {
            $dateGross = (float)$dateItems->sum('total_amount');
            $dateMdr = (float)$dateItems->sum('mdr_fee');
            $dateNet = (float)$dateItems->sum('net_amount');

            // Level 2: Group by shift
            $shiftGrouped = $dateItems->groupBy(function ($item) {
                $shiftId = $item->shift_id ?: ($item->transaction?->shift_id);
                return $shiftId ? (string)$shiftId : 'NO_SHIFT';
            });

            $shiftList = [];
            foreach ($shiftGrouped as $shiftKey => $shiftItems) {
                $first = $shiftItems->first();
                $shiftModel = $first->shift ?: $first->transaction?->shift;
                $cashierName = $shiftModel?->user?->name ?: $first->transaction?->user?->name ?: $first->creator?->name ?: 'Kasir';

                $shiftGross = (float)$shiftItems->sum('total_amount');
                $shiftMdr = (float)$shiftItems->sum('mdr_fee');
                $shiftNet = (float)$shiftItems->sum('net_amount');
                $shiftMdrPct = $shiftGross > 0 ? round(($shiftMdr / $shiftGross) * 100, 1) : 0;

                $shiftName = $shiftModel ? ($shiftModel->name ?: "Shift #{$shiftModel->id}") : ($shiftKey === 'NO_SHIFT' ? 'Transaksi Tanpa Sesi Shift' : "Shift #{$shiftKey}");

                $shiftList[] = [
                    'shift_key'      => $shiftKey,
                    'shift_id'       => $shiftModel?->id ?: ($shiftKey === 'NO_SHIFT' ? null : (int)$shiftKey),
                    'shift_name'     => $shiftName,
                    'shift_status'   => $shiftModel?->status ?: 'CLOSED',
                    'cashier_name'   => $cashierName,
                    'opened_at'      => $shiftModel?->opened_at,
                    'closed_at'      => $shiftModel?->closed_at,
                    'initial_cash'   => $shiftModel ? (float)$shiftModel->initial_cash : 0,
                    'total_orders'   => $shiftItems->count(),
                    'total_gross'    => $shiftGross,
                    'total_mdr'      => $shiftMdr,
                    'total_net'      => $shiftNet,
                    'mdr_pct'        => $shiftMdrPct,
                    'channels'       => $shiftItems->pluck('merchant_channel')->unique()->values()->all(),
                    'orders'         => $shiftItems->values(),
                ];
            }

            // Sort shifts (newest shift ID first)
            usort($shiftList, function ($a, $b) {
                return ($b['shift_id'] ?? 0) <=> ($a['shift_id'] ?? 0);
            });

            $tree[] = [
                'date'           => $dateKey,
                'total_orders'   => $dateItems->count(),
                'total_gross'    => $dateGross,
                'total_mdr'      => $dateMdr,
                'total_net'      => $dateNet,
                'mdr_pct'        => $dateGross > 0 ? round(($dateMdr / $dateGross) * 100, 1) : 0,
                'shifts_count'   => count($shiftList),
                'channels'       => $dateItems->pluck('merchant_channel')->unique()->values()->all(),
                'shifts'         => $shiftList,
            ];
        }

        // Channels list for dropdown filters
        $availableChannels = $allRows->pluck('merchant_channel')->filter()->unique()->values()->all();

        return response()->json([
            'data'     => $tree,
            'all_rows' => $allRows,
            'summary'  => [
                'total_dates'     => count($tree),
                'total_orders'    => $allRows->count(),
                'total_gross'     => (float)$allRows->sum('total_amount'),
                'total_mdr'       => (float)$allRows->sum('mdr_fee'),
                'total_net'       => (float)$allRows->sum('net_amount'),
                'overall_mdr_pct' => (float)$allRows->sum('total_amount') > 0
                    ? round(((float)$allRows->sum('mdr_fee') / (float)$allRows->sum('total_amount')) * 100, 1)
                    : 0,
                'channels'        => $availableChannels,
            ]
        ]);
    }

    /**
     * Update Net Amount for a single E-Commerce / Merchant Receivable (Auto-calculates Fee & Rate)
     */
    public function updateNetAmount(Request $request, $id)
    {
        $request->validate([
            'net_amount' => 'required|numeric|min:0',
            'notes'      => 'nullable|string',
        ]);

        $receivable = Receivable::findOrFail($id);
        $user = $request->user();

        $gross = (float)$receivable->total_amount;
        $net = (float)$request->net_amount;
        $fee = max(0, round($gross - $net, 2));
        $rate = $gross > 0 ? round(($fee / $gross) * 100, 2) : 0;

        $receivable->update([
            'net_amount'       => $net,
            'mdr_fee'          => $fee,
            'mdr_rate'         => $rate,
            'remaining_amount' => $net,
            'notes'            => $request->notes ?? $receivable->notes,
            'updated_by'       => $user?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Net amount dan potongan fee berhasil diperbarui!',
            'data'    => $receivable->fresh(['outlet', 'shift.user', 'transaction.shift.user', 'transaction.user']),
        ]);
    }

    /**
     * Batch update Total Net Amount at Shift Level / Date Level (Auto-distributes across orders & calculates Fee)
     */
    public function updateShiftNetAmount(Request $request)
    {
        $request->validate([
            'total_net_amount' => 'required|numeric|min:0',
            'shift_id'         => 'nullable',
            'date'             => 'nullable|date',
            'receivable_ids'   => 'nullable|array',
            'receivable_ids.*' => 'integer|exists:receivables,id',
            'merchant_channel' => 'nullable|string',
        ]);

        $user = $request->user();
        $targetNet = (float)$request->total_net_amount;

        $query = Receivable::where('ar_type', 'MERCHANT_ECOMMERCE');

        if (!empty($request->receivable_ids)) {
            $query->whereIn('id', $request->receivable_ids);
        } else {
            if ($request->filled('shift_id')) {
                if ($request->shift_id === 'NO_SHIFT' || $request->shift_id === 0 || $request->shift_id === '0') {
                    $query->whereNull('shift_id');
                } else {
                    $query->where('shift_id', $request->shift_id);
                }
            }
            if ($request->filled('date')) {
                $query->where('issue_date', $request->date);
            }
            if ($request->filled('merchant_channel') && $request->merchant_channel !== 'ALL') {
                $query->where('merchant_channel', $request->merchant_channel);
            }
        }

        $items = $query->get();

        if ($items->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ditemukan transaksi E-Commerce untuk sesi shift / filter ini.'
            ], 404);
        }

        $totalGross = (float)$items->sum('total_amount');
        $totalFee = max(0, round($totalGross - $targetNet, 2));
        $overallRate = $totalGross > 0 ? round(($totalFee / $totalGross) * 100, 2) : 0;

        $updatedItems = [];

        DB::transaction(function () use ($items, $targetNet, $totalGross, $user, &$updatedItems) {
            $allocatedNetSum = 0.0;
            $count = count($items);

            foreach ($items as $idx => $item) {
                if ($totalGross > 0) {
                    if ($idx === ($count - 1)) {
                        // Last item gets the remainder to eliminate rounding variance
                        $itemNet = round(max(0, $targetNet - $allocatedNetSum), 2);
                    } else {
                        $itemNet = round(($item->total_amount / $totalGross) * $targetNet, 2);
                        $allocatedNetSum += $itemNet;
                    }
                } else {
                    $itemNet = round($targetNet / $count, 2);
                }

                $itemFee = max(0, round($item->total_amount - $itemNet, 2));
                $itemRate = $item->total_amount > 0 ? round(($itemFee / $item->total_amount) * 100, 2) : 0;

                $item->update([
                    'net_amount'       => $itemNet,
                    'mdr_fee'          => $itemFee,
                    'mdr_rate'         => $itemRate,
                    'remaining_amount' => $itemNet,
                    'updated_by'       => $user?->id,
                ]);

                $updatedItems[] = $item->fresh(['outlet', 'shift.user', 'transaction.shift.user', 'transaction.user']);
            }
        });

        return response()->json([
            'success'          => true,
            'message'          => "Berhasil memperbarui Net Amount Total Shift menjadi Rp " . number_format($targetNet, 0, ',', '.') . " (Fee: Rp " . number_format($totalFee, 0, ',', '.') . " / {$overallRate}%) pada " . count($updatedItems) . " pesanan!",
            'total_net_amount' => $targetNet,
            'total_mdr_fee'    => $totalFee,
            'overall_rate'     => $overallRate,
            'updated_count'    => count($updatedItems),
            'data'             => $updatedItems,
        ]);
    }

    /**
     * Settle single AR Merchant record to Bank (Only for QRIS)
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

        if ($receivable->ar_type === 'MERCHANT_ECOMMERCE') {
            return response()->json([
                'message' => 'AR E-Commerce tidak memerlukan pencairan manual di POS karena pencairannya diproses otomatis langsung dari aplikasi e-commerce terkait. Buku piutang e-commerce hanya berfungsi untuk rekonsiliasi / cross-check besaran transaksi.'
            ], 422);
        }

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
            'message' => 'Pencairan AR Merchant QRIS berhasil dicatat ke rekening bank!',
            'data'    => $receivable->load(['payments.receiver', 'outlet'])
        ]);
    }

    /**
     * Settle multiple selected AR Merchant records to Bank (Bulk Settlement - Only for QRIS)
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

        $receivables = Receivable::whereIn('id', $request->ids)
            ->where('ar_type', 'MERCHANT_QRIS')
            ->get();

        if ($receivables->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada transaksi QRIS valid yang dapat dicairkan. Transaksi E-Commerce tidak memiliki opsi pencairan manual di POS karena diproses langsung via aplikasi e-commerce.'
            ], 422);
        }

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
                    'notes'          => "Pencairan Masal Piutang QRIS {$r->merchant_channel} ke {$settledBank}",
                    'received_by'    => $user->id,
                ]);

                $count++;
                $totalCair += $net;
            }
        });

        return response()->json([
            'success'     => true,
            'message'     => "Berhasil mencairkan {$count} transaksi AR QRIS total Rp " . number_format($totalCair, 0, ',', '.') . " ke {$settledBank}!",
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

        // Perform fast backfill for missing shift_id if any
        try {
            DB::statement("
                UPDATE receivables r
                INNER JOIN transactions t ON r.transaction_id = t.id
                SET r.shift_id = t.shift_id
                WHERE r.business_id = {$businessId} AND r.shift_id IS NULL AND t.shift_id IS NOT NULL
            ");
        } catch (\Throwable $e) {}

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
                'shift_id'          => $first->shift_id,
                'customer_id'       => $first->customer_id,
                'ar_type'           => $arType,
                'merchant_channel'  => $channel,
                'order_number'      => $orderNo,
                'customer_name'     => $isQris ? "AR Merchant - {$channel}" : "Buku E-Commerce - {$channel}",
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
                'settlement_status' => $isQris ? 'UNSETTLED' : 'AUTO_ECOMMERCE',
                'notes'             => $isQris ? "Piutang QRIS {$channel} — Order #{$orderNo}" : "Buku Rekonsiliasi E-Commerce {$channel} — Order #{$orderNo} (Pencairan Otomatis via Apk)",
                'created_by'        => $first->user_id,
            ]);
        }
    }
}


