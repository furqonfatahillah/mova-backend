<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Menu;
use App\Models\Customer;
use App\Models\PointRedemption;
use App\Models\Receivable;
use App\Models\Discount;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReportController extends Controller
{
    private function getTargetOutletId(Request $request): ?int
    {
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            return (int)$user->outlet_id;
        }
        if ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            return (int)$request->outlet_id;
        }
        return null;
    }

    private function getContextNames(Request $request, ?int $outletId): array
    {
        $user = $request->user();
        $business = $user->business;
        $businessName = $business?->name ?: ($user->business_name ?: 'MOVA POS');

        $outletName = 'Semua Cabang (Konsolidasi)';
        if ($outletId) {
            $ot = Outlet::find($outletId);
            if ($ot) $outletName = $ot->name;
        }

        return [$businessName, $outletName];
    }

    /**
     * 1. Laporan Penjualan per Produk
     */
    public function salesByProduct(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $businessId = $request->user()->business_id;
        $outletId   = $this->getTargetOutletId($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId);

        $query = Transaction::with(['menu'])
            ->where('business_id', $businessId)
            ->whereBetween('date', [$request->from, $request->to]);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $transactions = $query->get();

        // Group by menu_id (or fallback key)
        $grouped = [];
        foreach ($transactions as $t) {
            $menuId = $t->menu_id ?: ('CUSTOM_' . ($t->notes ?: 'Lain-lain'));
            if (!isset($grouped[$menuId])) {
                $menu = $t->menu;
                $grouped[$menuId] = [
                    'menu_id'            => $t->menu_id,
                    'code'               => $menu?->code ?: ($t->menu_id ? (string)$t->menu_id : '-'),
                    'name'               => $menu ? ($menu->name . ' (Reguler)') : ($t->notes ?: 'Produk Khusus'),
                    'qty_sold'           => 0,
                    'qty_refund'         => 0,
                    'unit'               => $menu?->unit ?: 'Cup',
                    'cost_price'         => (float)($menu?->cost_price ?: 0),
                    'price'              => (float)($menu?->price ?: ($t->total_price / max(1, $t->qty))),
                    'discount_amount'    => 0.0,
                    'total_sales'        => 0.0,
                    'total_refund'       => 0.0,
                ];
            }

            if ($t->status === 'PAID') {
                $grouped[$menuId]['qty_sold'] += (int)$t->qty;
                $grouped[$menuId]['discount_amount'] += (float)$t->discount_amount;
                $grouped[$menuId]['total_sales'] += (float)$t->subtotal;
            } elseif ($t->status === 'CANCELLED') {
                $grouped[$menuId]['qty_refund'] += (int)$t->qty;
                $grouped[$menuId]['total_refund'] += (float)$t->subtotal;
            }
        }

        $items = array_values($grouped);

        // Filter by search if provided
        if ($request->filled('search')) {
            $s = strtolower(trim($request->search));
            $items = array_filter($items, function ($it) use ($s) {
                return str_contains(strtolower($it['name']), $s) || str_contains(strtolower($it['code']), $s);
            });
            $items = array_values($items);
        }

        // Sort by total_sales descending (or qty_sold)
        usort($items, fn($a, $b) => $b['total_sales'] <=> $a['total_sales']);

        // Assign row numbers
        foreach ($items as $idx => &$item) {
            $item['no'] = $idx + 1;
        }
        unset($item);

        $summary = [
            'total_qty_sold'    => array_sum(array_column($items, 'qty_sold')),
            'total_qty_refund'  => array_sum(array_column($items, 'qty_refund')),
            'total_modal'       => array_reduce($items, fn($carry, $it) => $carry + ($it['cost_price'] * $it['qty_sold']), 0.0),
            'total_discount'    => array_sum(array_column($items, 'discount_amount')),
            'total_sales'       => array_sum(array_column($items, 'total_sales')),
            'total_refund'      => array_sum(array_column($items, 'total_refund')),
        ];

        return response()->json([
            'report_title'  => 'LAPORAN PENJUALAN PER PRODUK',
            'business_name' => $businessName,
            'outlet_name'   => $outletName,
            'period'        => ['from' => $request->from, 'to' => $request->to],
            'summary'       => $summary,
            'items'         => $items,
        ]);
    }

    /**
     * 2. Laporan Penukaran Poin
     */
    public function pointRedemptions(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $businessId = $request->user()->business_id;
        $outletId   = $this->getTargetOutletId($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId);

        $query = PointRedemption::with(['customer', 'discount', 'creator', 'transaction.outlet'])
            ->where('business_id', $businessId)
            ->whereBetween('created_at', ["{$request->from} 00:00:00", "{$request->to} 23:59:59"])
            ->orderByDesc('id');

        if ($outletId) {
            $query->whereHas('transaction', fn($tq) => $tq->where('outlet_id', $outletId));
        }

        if ($request->filled('search')) {
            $s = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($s) {
                $q->where('order_number', 'like', $s)
                  ->orWhere('description', 'like', $s)
                  ->orWhereHas('customer', fn($cq) => $cq->where('name', 'like', $s)->orWhere('phone', 'like', $s));
            });
        }

        $redemptions = $query->get();

        $items = [];
        foreach ($redemptions as $idx => $r) {
            $items[] = [
                'no'           => $idx + 1,
                'date'         => $r->created_at ? $r->created_at->format('d/m/Y') : '',
                'created_at'   => $r->created_at ? $r->created_at->format('d/m/Y H:i:s') : '',
                'created_by'   => $r->creator?->name ?: 'Kasir',
                'warehouse'    => $r->transaction?->outlet?->name ?: $outletName,
                'customer'     => $r->customer?->name ?: '-',
                'cashier'      => $r->creator?->name ?: 'Kasir',
                'order_number' => $r->order_number ?: '-',
                'penukaran'    => $r->discount?->name ?: ($r->description ?: 'Penukaran Poin'),
                'qty'          => 1,
                'nilai'        => (float)($r->discount?->value ?: 0),
                'points_used'  => (int)$r->points_used,
            ];
        }

        $summary = [
            'total_qty'    => count($items),
            'total_nilai'  => array_sum(array_column($items, 'nilai')),
            'total_points' => array_sum(array_column($items, 'points_used')),
        ];

        return response()->json([
            'report_title'  => 'LAPORAN PENUKARAN POIN',
            'business_name' => $businessName,
            'outlet_name'   => $outletName,
            'period'        => ['from' => $request->from, 'to' => $request->to],
            'summary'       => $summary,
            'items'         => $items,
        ]);
    }

    /**
     * 3. Laporan Pembayaran Penjualan
     */
    public function salesPayments(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $businessId = $request->user()->business_id;
        $outletId   = $this->getTargetOutletId($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId);

        $query = Transaction::with(['outlet', 'user', 'customer'])
            ->where('business_id', $businessId)
            ->where('status', 'PAID')
            ->whereBetween('date', [$request->from, $request->to])
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        if ($request->filled('payment_method') && $request->payment_method !== 'ALL') {
            $query->where('payment_method', $request->payment_method);
        }

        $transactions = $query->get();

        // Group rows by order_number
        $grouped = [];
        foreach ($transactions as $t) {
            $orderNo = $t->order_number;
            if (!isset($grouped[$orderNo])) {
                $payMethod = $t->payment_method ?: 'Tunai';
                $isKasbon = in_array(strtoupper($payMethod), ['KASBON', 'PIUTANG']);

                // Determine deposit account string matching user specification
                $depositAccount = 'Kas Kecil';
                $pmLower = strtolower($payMethod);
                if (str_contains($pmLower, 'qris')) {
                    $depositAccount = 'QRIS | 005001005015564';
                } elseif (str_contains($pmLower, 'bri') || str_contains($pmLower, 'transfer') || str_contains($pmLower, 'digital')) {
                    $depositAccount = 'BRI | 005001005015564';
                } elseif ($payMethod !== 'Tunai' && $payMethod !== 'Cash') {
                    $depositAccount = $payMethod;
                }

                $grouped[$orderNo] = [
                    'order_number'    => $orderNo,
                    'date'            => $t->date ? date('d/m/Y', strtotime($t->date)) : ($t->created_at ? $t->created_at->format('d/m/Y') : ''),
                    'time'            => $t->created_at ? $t->created_at->format('H:i') : '00:00',
                    'created_at'      => $t->created_at ? $t->created_at->format('d/m/Y H:i:s') : '',
                    'created_by'      => $t->user?->name ?: 'Lulu',
                    'warehouse'       => $t->outlet?->name ?: $outletName,
                    'payment_number'  => '',
                    'customer'        => $t->customer_name ?: ($t->customer?->name ?: ''),
                    'payment_method'  => $payMethod,
                    'deposit_account' => $depositAccount,
                    'total_amount'    => 0.0,
                    'amount_paid'     => 0.0,
                    'receivable'      => 0.0,
                    'cashier'         => $t->user?->name ?: 'Lulu',
                    'is_kasbon'       => $isKasbon,
                ];
            }

            $grouped[$orderNo]['total_amount'] += (float)$t->subtotal;
        }

        $items = [];
        $rowNo = 1;
        foreach ($grouped as $ord) {
            $total = $ord['total_amount'];
            $paid = $ord['is_kasbon'] ? 0.0 : $total;
            $receivable = $ord['is_kasbon'] ? $total : 0.0;

            $items[] = [
                'no'               => $rowNo++,
                'date'             => $ord['date'],
                'time'             => $ord['time'],
                'created_at'       => $ord['created_at'],
                'created_by'       => $ord['created_by'],
                'warehouse'        => $ord['warehouse'],
                'order_number'     => $ord['order_number'],
                'payment_number'   => $ord['payment_number'],
                'customer'         => $ord['customer'],
                'payment_method'   => $ord['payment_method'],
                'deposit_account'  => $ord['deposit_account'],
                'total_transaction'=> $total,
                'paid_amount'      => $paid,
                'receivable_amount'=> $receivable,
                'cashier'          => $ord['cashier'],
            ];
        }

        if ($request->filled('search')) {
            $s = strtolower(trim($request->search));
            $items = array_filter($items, function ($it) use ($s) {
                return str_contains(strtolower($it['order_number']), $s)
                    || str_contains(strtolower($it['customer']), $s)
                    || str_contains(strtolower($it['payment_method']), $s)
                    || str_contains(strtolower($it['warehouse']), $s)
                    || str_contains(strtolower($it['cashier']), $s);
            });
            $items = array_values($items);
            foreach ($items as $idx => &$it) {
                $it['no'] = $idx + 1;
            }
            unset($it);
        }

        $summary = [
            'total_count'       => count($items),
            'total_transaction' => array_sum(array_column($items, 'total_transaction')),
            'total_paid'        => array_sum(array_column($items, 'paid_amount')),
            'total_receivable'  => array_sum(array_column($items, 'receivable_amount')),
        ];

        return response()->json([
            'report_title'  => 'LAPORAN PEMBAYARAN PENJUALAN',
            'business_name' => $businessName,
            'outlet_name'   => $outletName,
            'period'        => ['from' => $request->from, 'to' => $request->to],
            'summary'       => $summary,
            'items'         => $items,
        ]);
    }

    /**
     * 4. Laporan Transaksi Penjualan (Detail Item & HPP)
     */
    public function salesTransactionsDetailed(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $businessId = $request->user()->business_id;
        $outletId   = $this->getTargetOutletId($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId);

        $query = Transaction::with(['menu', 'user', 'outlet', 'customer', 'discount'])
            ->where('business_id', $businessId)
            ->where('status', 'PAID')
            ->whereBetween('date', [$request->from, $request->to])
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        if ($request->filled('payment_method') && $request->payment_method !== 'ALL') {
            $query->where('payment_method', $request->payment_method);
        }

        $transactions = $query->get();

        $items = [];
        foreach ($transactions as $idx => $t) {
            $menu = $t->menu;
            $payMethod = $t->payment_method ?: 'Tunai';
            $isKasbon = in_array(strtoupper($payMethod), ['KASBON', 'PIUTANG']);

            $depositAccount = 'Kas Kecil';
            $pmLower = strtolower($payMethod);
            if (str_contains($pmLower, 'qris')) {
                $depositAccount = 'QRIS | 005001005015564';
            } elseif (str_contains($pmLower, 'bri') || str_contains($pmLower, 'transfer') || str_contains($pmLower, 'digital')) {
                $depositAccount = 'BRI | 005001005015564';
            } elseif ($payMethod !== 'Tunai' && $payMethod !== 'Cash') {
                $depositAccount = $payMethod;
            }

            $qty = (int)$t->qty;
            $hpp = (float)($menu?->cost_price ?: 0);
            $price = (float)($menu?->price ?: ($t->total_price / max(1, $qty)));
            $subtotal = (float)$t->subtotal;
            $disc = (float)($t->discount_amount ?: 0);
            $profit = $subtotal - ($hpp * $qty);

            $items[] = [
                'no'               => $idx + 1,
                'date'             => $t->created_at ? $t->created_at->format('d/m/Y H:i:s') : ($t->date . ' 00:00:00'),
                'created_at'       => $t->created_at ? $t->created_at->format('d/m/Y H:i:s') : '',
                'created_by'       => $t->user?->name ?: 'Lulu',
                'order_number'     => $t->order_number,
                'customer'         => $t->customer_name ?: ($t->customer?->name ?: 'Walk-in Customer'),
                'promo'            => $t->discount_name ?: ($t->discount?->name ?: ''),
                'payment_method'   => $payMethod,
                'deposit_account'  => $depositAccount,
                'product_code'     => $menu?->code ?: ($t->menu_id ? (string)$t->menu_id : '-'),
                'product_name'     => $menu?->name ?: ($t->notes ?: 'Menu'),
                'product_category' => $menu?->category ?: 'KOPI',
                'sales_type'       => $t->order_type ?: 'Dine-in',
                'hpp'              => $hpp,
                'qty'              => $qty,
                'unit'             => $menu?->unit ?: 'Cup',
                'price'            => $price,
                'discount'         => $disc,
                'subtotal'         => $subtotal,
                'discount_extra'   => 0.0,
                'discount_customer'=> 0.0,
                'tax'              => 0.0,
                'service_charge'   => 0.0,
                'shipping'         => 0.0,
                'total_sale'       => $subtotal,
                'receivable'       => $isKasbon ? $subtotal : 0.0,
                'profit'           => $profit,
                'cashier'          => $t->user?->name ?: 'Lulu',
                'receipt_printed'  => 0,
            ];
        }

        if ($request->filled('search')) {
            $s = strtolower(trim($request->search));
            $items = array_filter($items, function ($it) use ($s) {
                return str_contains(strtolower($it['order_number']), $s)
                    || str_contains(strtolower($it['customer']), $s)
                    || str_contains(strtolower($it['product_name']), $s)
                    || str_contains(strtolower($it['product_code']), $s)
                    || str_contains(strtolower($it['cashier']), $s);
            });
            $items = array_values($items);
            foreach ($items as $idx => &$it) {
                $it['no'] = $idx + 1;
            }
            unset($it);
        }

        $summary = [
            'total_items'      => count($items),
            'total_qty'        => array_sum(array_column($items, 'qty')),
            'total_discount'   => array_sum(array_column($items, 'discount')),
            'total_sale'       => array_sum(array_column($items, 'total_sale')),
            'total_receivable' => array_sum(array_column($items, 'receivable')),
            'total_profit'     => array_sum(array_column($items, 'profit')),
        ];

        return response()->json([
            'report_title'  => 'Laporan Transaksi Penjualan',
            'business_name' => $businessName,
            'outlet_name'   => $outletName,
            'period'        => ['from' => $request->from, 'to' => $request->to],
            'summary'       => $summary,
            'items'         => $items,
        ]);
    }

    /**
     * 5. Laporan Daftar Penjualan per Customer
     */
    public function salesByCustomer(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $businessId = $request->user()->business_id;
        $outletId   = $this->getTargetOutletId($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId);

        $query = Transaction::with(['menu', 'user', 'outlet', 'customer'])
            ->where('business_id', $businessId)
            ->where('status', 'PAID')
            ->whereBetween('date', [$request->from, $request->to])
            ->orderBy('customer_name', 'asc')
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $transactions = $query->get();

        $items = [];
        foreach ($transactions as $idx => $t) {
            $menu = $t->menu;
            $customer = $t->customer;
            $payMethod = $t->payment_method ?: 'Tunai';
            $isKasbon = in_array(strtoupper($payMethod), ['KASBON', 'PIUTANG']);
            $qty = (int)$t->qty;
            $price = (float)($menu?->price ?: ($t->total_price / max(1, $qty)));
            $subtotal = (float)$t->subtotal;
            $paid = $isKasbon ? 0.0 : $subtotal;
            $receivable = $isKasbon ? $subtotal : 0.0;

            $items[] = [
                'no'               => $idx + 1,
                'date'             => $t->created_at ? $t->created_at->format('d/m/Y H:i:s') : ($t->date . ' 00:00:00'),
                'customer_code'    => $customer?->code ?: ($t->customer_id ? (string)$t->customer_id : '-'),
                'customer_name'    => $t->customer_name ?: ($customer?->name ?: 'Walk-in Customer'),
                'customer_group'   => 'Reguler',
                'order_number'     => $t->order_number,
                'product_name'     => $menu?->name ?: ($t->notes ?: 'Menu'),
                'qty'              => $qty,
                'unit'             => $menu?->unit ?: 'Cup',
                'price'            => $price,
                'discount'         => (float)($t->discount_amount ?: 0),
                'tax'              => 0.0,
                'service_charge'   => 0.0,
                'shipping'         => 0.0,
                'total'            => $subtotal,
                'total_paid'       => $paid,
                'payment_method'   => $payMethod,
                'receivable'       => $receivable,
                'cashier'          => $t->user?->name ?: 'Lulu',
            ];
        }

        if ($request->filled('search')) {
            $s = strtolower(trim($request->search));
            $items = array_filter($items, function ($it) use ($s) {
                return str_contains(strtolower($it['customer_name']), $s)
                    || str_contains(strtolower($it['customer_code']), $s)
                    || str_contains(strtolower($it['product_name']), $s)
                    || str_contains(strtolower($it['order_number']), $s);
            });
            $items = array_values($items);
            foreach ($items as $idx => &$it) {
                $it['no'] = $idx + 1;
            }
            unset($it);
        }

        $summary = [
            'total_rows'       => count($items),
            'total_qty'        => array_sum(array_column($items, 'qty')),
            'total_amount'     => array_sum(array_column($items, 'total')),
            'total_paid'       => array_sum(array_column($items, 'total_paid')),
            'total_receivable' => array_sum(array_column($items, 'receivable')),
        ];

        return response()->json([
            'report_title'  => 'LAPORAN DAFTAR PENJUALAN PER CUSTOMER',
            'business_name' => $businessName,
            'outlet_name'   => $outletName,
            'period'        => ['from' => $request->from, 'to' => $request->to],
            'summary'       => $summary,
            'items'         => $items,
        ]);
    }

    /**
     * 6. Laporan Waktu Teramai (Hourly / Peak Hours Report)
     */
    public function peakHours(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $businessId = $request->user()->business_id;
        $outletId   = $this->getTargetOutletId($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId);

        $query = Transaction::where('business_id', $businessId)
            ->where('status', 'PAID')
            ->whereBetween('date', [$request->from, $request->to]);

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $transactions = $query->get();

        // 24-hour buckets (00:00 to 23:00)
        $hourlyData = [];
        for ($h = 0; $h < 24; $h++) {
            $key = sprintf('%02d:00', $h);
            $hourlyData[$key] = [
                'time'         => $key,
                'total_sales'  => 0.0,
                'orders'       => [],
                'total_qty'    => 0,
                'guests'       => [],
            ];
        }

        foreach ($transactions as $t) {
            $timeStr = $t->created_at ? $t->created_at->format('H') : '12';
            $hour = (int)$timeStr;
            if ($hour < 0 || $hour > 23) $hour = 12;
            $key = sprintf('%02d:00', $hour);

            $hourlyData[$key]['total_sales'] += (float)$t->subtotal;
            $hourlyData[$key]['orders'][$t->order_number] = true;
            $hourlyData[$key]['total_qty'] += (int)$t->qty;
            $guestKey = $t->customer_id ? ('c_' . $t->customer_id) : ($t->customer_name ?: $t->order_number);
            $hourlyData[$key]['guests'][$guestKey] = true;
        }

        $grandSales = array_sum(array_column($hourlyData, 'total_sales'));
        $grandTransactions = array_sum(array_map(fn($h) => count($h['orders']), $hourlyData));
        $grandProducts = array_sum(array_column($hourlyData, 'total_qty'));
        $grandGuests = array_sum(array_map(fn($h) => count($h['guests']), $hourlyData));

        $items = [];
        $idx = 1;
        foreach ($hourlyData as $key => $h) {
            $trxCount = count($h['orders']);
            $guestCount = count($h['guests']);
            $sales = (float)$h['total_sales'];
            $avgSales = $trxCount > 0 ? round($sales / $trxCount, 2) : 0.0;
            $salesPct = $grandSales > 0 ? round(($sales / $grandSales) * 100, 2) : 0.0;
            $trxPct = $grandTransactions > 0 ? round(($trxCount / $grandTransactions) * 100, 2) : 0.0;
            $prodPct = $grandProducts > 0 ? round(($h['total_qty'] / $grandProducts) * 100, 2) : 0.0;
            $guestPct = $grandGuests > 0 ? round(($guestCount / $grandGuests) * 100, 2) : 0.0;

            $items[] = [
                'no'               => $idx++,
                'waktu'            => $key,
                'total_penjualan'  => $sales,
                'avg_penjualan'    => $avgSales,
                'penjualan_pct'    => $salesPct,
                'transaksi'        => $trxCount,
                'transaksi_pct'    => $trxPct,
                'produk'           => $h['total_qty'],
                'produk_pct'       => $prodPct,
                'tamu'             => $guestCount,
                'tamu_pct'         => $guestPct,
            ];
        }

        $summary = [
            'total_sales'        => $grandSales,
            'total_transactions' => $grandTransactions,
            'total_products'     => $grandProducts,
            'total_guests'       => $grandGuests,
            'avg_sales_overall'  => $grandTransactions > 0 ? round($grandSales / max(1, $grandTransactions), 2) : 0.0,
        ];

        return response()->json([
            'report_title'  => 'LAPORAN WAKTU TERAMAI',
            'business_name' => $businessName,
            'outlet_name'   => $outletName,
            'period'        => ['from' => $request->from, 'to' => $request->to],
            'summary'       => $summary,
            'items'         => $items,
        ]);
    }

    /**
     * 7. Laporan Piutang Customer
     */
    public function customerReceivables(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $businessId = $request->user()->business_id;
        $outletId   = $this->getTargetOutletId($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId);

        $query = Receivable::with(['customer', 'outlet'])
            ->where('business_id', $businessId)
            ->whereBetween('issue_date', [$request->from, $request->to])
            ->orderBy('issue_date', 'asc');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        if ($request->filled('search')) {
            $s = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($s) {
                $q->where('customer_name', 'like', $s)
                  ->orWhere('order_number', 'like', $s)
                  ->orWhere('receivable_no', 'like', $s);
            });
        }

        $receivables = $query->get();

        $items = [];
        $todayTs = strtotime(date('Y-m-d'));

        foreach ($receivables as $idx => $r) {
            $issueTs = strtotime($r->issue_date);
            $diffDays = max(0, (int)floor(($todayTs - $issueTs) / 86400));

            $items[] = [
                'no'           => $idx + 1,
                'customer'     => $r->customer_name ?: ($r->customer?->name ?: 'Walk-in Customer'),
                'tanggal'      => $r->issue_date ? date('d/m/Y', strtotime($r->issue_date)) : '',
                'jam'          => $r->created_at ? $r->created_at->format('H:i') : '00:00',
                'no_penjualan' => $r->order_number ?: ($r->receivable_no ?: '-'),
                'piutang'      => (float)$r->total_amount,
                'dibayar'      => (float)$r->paid_amount,
                'sisa_piutang' => (float)$r->remaining_amount,
                'usia_piutang' => "{$diffDays} Hari",
                'usia_days'    => $diffDays,
                'jatuh_tempo'  => $r->due_date ? date('d/m/Y', strtotime($r->due_date)) : '-',
                'status'       => $r->status,
            ];
        }

        $summary = [
            'total_rows'         => count($items),
            'total_piutang'      => array_sum(array_column($items, 'piutang')),
            'total_dibayar'      => array_sum(array_column($items, 'dibayar')),
            'total_sisa_piutang' => array_sum(array_column($items, 'sisa_piutang')),
        ];

        return response()->json([
            'report_title'  => 'LAPORAN PIUTANG CUSTOMER',
            'business_name' => $businessName,
            'outlet_name'   => $outletName,
            'period'        => ['from' => $request->from, 'to' => $request->to],
            'summary'       => $summary,
            'items'         => $items,
        ]);
    }

    /**
     * 8. Laporan Promo
     */
    public function promos(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $businessId = $request->user()->business_id;
        $outletId   = $this->getTargetOutletId($request);
        [$businessName, $outletName] = $this->getContextNames($request, $outletId);

        $query = Transaction::where('business_id', $businessId)
            ->where('status', 'PAID')
            ->where('discount_amount', '>', 0)
            ->whereBetween('date', [$request->from, $request->to])
            ->orderBy('date', 'asc');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $transactions = $query->get();

        // Group by (date, promo_name)
        $grouped = [];
        foreach ($transactions as $t) {
            $dateFormatted = date('d/m/Y', strtotime($t->date));
            $promoName = $t->discount_name ?: 'Promo Diskon';
            $promoType = $t->discount_type ?: 'Persentase';
            $key = $t->date . '|' . $promoName;

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'date_raw'         => $t->date,
                    'tanggal'          => $dateFormatted,
                    'promo'            => $promoName,
                    'jenis'            => $promoType,
                    'orders'           => [],
                    'nilai'            => 0.0,
                    'penjualan_promo'  => 0.0,
                ];
            }

            $grouped[$key]['orders'][$t->order_number] = true;
            $grouped[$key]['nilai'] += (float)$t->discount_amount;
            $grouped[$key]['penjualan_promo'] += (float)$t->subtotal;
        }

        $items = [];
        $idx = 1;
        foreach ($grouped as $g) {
            $trxCount = count($g['orders']);
            $items[] = [
                'no'               => $idx++,
                'tanggal'          => $g['tanggal'],
                'promo'            => $g['promo'],
                'jenis'            => $g['jenis'],
                'jumlah_transaksi' => $trxCount,
                'nilai'            => (float)$g['nilai'],
                'penjualan_promo'  => (float)$g['penjualan_promo'],
            ];
        }

        if ($request->filled('search')) {
            $s = strtolower(trim($request->search));
            $items = array_filter($items, function ($it) use ($s) {
                return str_contains(strtolower($it['promo']), $s)
                    || str_contains(strtolower($it['jenis']), $s)
                    || str_contains(strtolower($it['tanggal']), $s);
            });
            $items = array_values($items);
            foreach ($items as $k => &$it) {
                $it['no'] = $k + 1;
            }
            unset($it);
        }

        $summary = [
            'total_rows'             => count($items),
            'total_promo'            => array_sum(array_column($items, 'jumlah_transaksi')),
            'total_nilai'            => array_sum(array_column($items, 'nilai')),
            'total_penjualan_promo'  => array_sum(array_column($items, 'penjualan_promo')),
        ];

        return response()->json([
            'report_title'  => 'LAPORAN PROMO',
            'business_name' => $businessName,
            'outlet_name'   => $outletName,
            'period'        => ['from' => $request->from, 'to' => $request->to],
            'summary'       => $summary,
            'items'         => $items,
        ]);
    }
}
