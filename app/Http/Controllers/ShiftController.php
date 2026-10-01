<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Outlet;
use App\Models\StockMovement;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends Controller
{
    /**
     * List all shifts with optional filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        $query = Shift::with(['user', 'closedByUser', 'creator', 'updater', 'outlet', 'shiftSchedule'])
            ->withCount(['transactions as transactions_count' => function ($q) {
                $q->where('status', 'PAID')->select(DB::raw('COUNT(DISTINCT COALESCE(order_number, CAST(id AS CHAR)))'));
            }])
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($isOutletBounded) {
            $query->where('outlet_id', (int)$user->outlet_id);
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('date')) {
            $query->whereDate('opened_at', $request->date);
        }

        if ($request->from) {
            $query->whereDate('opened_at', '>=', $request->from);
        }

        if ($request->to) {
            $query->whereDate('opened_at', '<=', $request->to);
        }

        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('shift_id')) {
            $query->where('id', $request->shift_id);
        }

        if ($request->filled('shift_schedule_id')) {
            $query->where('shift_schedule_id', $request->shift_schedule_id);
        }

        if ($request->filled('shift_name')) {
            $query->where('shift_name', 'like', "%{$request->shift_name}%");
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($sub) use ($s) {
                $sub->where('shift_name', 'like', "%{$s}%")
                    ->orWhere('id', 'like', "%{$s}%")
                    ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', "%{$s}%"));
            });
        }

        $shifts = $query->limit(300)->get();
        $shiftIds = $shifts->pluck('id');

        $paidTransactions = Transaction::whereIn('shift_id', $shiftIds)
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->select('shift_id', 'payment_method', 'dp_payment_method', 'total_price', 'amount_paid')
            ->get();

        $breakdownByShift = [];
        foreach ($paidTransactions as $t) {
            $sId = $t->shift_id;
            if (!isset($breakdownByShift[$sId])) {
                $breakdownByShift[$sId] = [
                    'cash'        => 0.0,
                    'qris'        => 0.0,
                    'grab'        => 0.0,
                    'transfer'    => 0.0,
                    'debit'       => 0.0,
                    'other'       => 0.0,
                    'total_sales' => 0.0,
                    'details'     => [],
                ];
            }

            $method = strtoupper(trim($t->payment_method ?? 'CASH'));
            $price = (float)$t->total_price;
            $breakdownByShift[$sId]['total_sales'] += $price;

            if (!isset($breakdownByShift[$sId]['details'][$method])) {
                $breakdownByShift[$sId]['details'][$method] = 0.0;
            }
            $breakdownByShift[$sId]['details'][$method] += $price;

            if ($method === 'CASH' || $method === 'TUNAI') {
                $breakdownByShift[$sId]['cash'] += $price;
            } elseif (str_contains($method, 'QRIS')) {
                $breakdownByShift[$sId]['qris'] += $price;
            } elseif (str_contains($method, 'GRAB') || str_contains($method, 'GOFOOD') || str_contains($method, 'SHOPEE') || str_contains($method, 'TIKTOK')) {
                $breakdownByShift[$sId]['grab'] += $price;
            } elseif (str_contains($method, 'TRANSFER')) {
                $breakdownByShift[$sId]['transfer'] += $price;
            } elseif (str_contains($method, 'DEBIT') || str_contains($method, 'EDC') || str_contains($method, 'CREDIT') || str_contains($method, 'KARTU')) {
                $breakdownByShift[$sId]['debit'] += $price;
            } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                $dpPaid = (float)($t->amount_paid ?? 0);
                if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                    $breakdownByShift[$sId]['cash'] += $dpPaid;
                } else {
                    $breakdownByShift[$sId]['other'] += $dpPaid;
                }
            } else {
                $breakdownByShift[$sId]['other'] += $price;
            }
        }

        foreach ($shifts as $s) {
            $bd = $breakdownByShift[$s->id] ?? [
                'cash'        => 0.0,
                'qris'        => 0.0,
                'grab'        => 0.0,
                'transfer'    => 0.0,
                'debit'       => 0.0,
                'other'       => 0.0,
                'total_sales' => 0.0,
                'details'     => [],
            ];
            $s->cash_sales = $bd['cash'];
            $s->qris_sales = $bd['qris'];
            $s->grab_sales = $bd['grab'];
            $s->transfer_sales = $bd['transfer'];
            $s->debit_sales = $bd['debit'];
            $s->other_sales = $bd['other'];
            $s->payment_breakdown = $bd['details'];
            if ($s->status === 'OPEN' || ($s->system_cash == 0 && $bd['total_sales'] > 0)) {
                $s->system_cash = $bd['total_sales'];
            }
        }

        return response()->json($shifts);
    }

    /**
     * Get currently active / open shift.
     */
    public function active(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);
        $query = Shift::with(['user', 'outlet', 'shiftSchedule'])->where('status', 'OPEN');
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }
        $shift = $query->orderByDesc('opened_at')->first();

        if (!$shift) {
            $lastShift = Shift::with(['user', 'closedByUser', 'outlet', 'shiftSchedule'])
                ->where('status', 'CLOSED')
                ->when($outletId && $outletId !== 'ALL' && $outletId !== 'all', fn($q) => $q->where('outlet_id', $outletId))
                ->orderByDesc('closed_at')
                ->orderByDesc('id')
                ->first();

            $lastClosedData = $lastShift ? [
                'id'              => $lastShift->id,
                'shift_name'      => $lastShift->shift_name,
                'outlet_id'       => $lastShift->outlet_id,
                'outlet_name'     => $lastShift->outlet?->name,
                'cashier_name'    => $lastShift->user?->name,
                'closed_by_name'  => $lastShift->closedByUser?->name ?? $lastShift->user?->name,
                'opened_at'       => $lastShift->opened_at,
                'closed_at'       => $lastShift->closed_at,
                'initial_cash'    => (float)$lastShift->initial_cash,
                'closing_cash'    => (float)$lastShift->closing_cash,
                'system_cash'     => (float)$lastShift->system_cash,
                'cash_difference' => (float)$lastShift->cash_difference,
                'notes'           => $lastShift->notes,
            ] : null;

            return response()->json([
                'shift'             => null,
                'last_closed_shift' => $lastClosedData,
            ]);
        }

        $paidTransactions = $shift->transactions()->where('status', 'PAID')->get();
        $totalTransactions = $paidTransactions->unique(fn($t) => $t->order_number ?: ('trx_' . $t->id))->count();
        $totalSales = (float)$paidTransactions->sum('total_price');

        $cashSales = 0.0;
        $qrisSales = 0.0;
        $grabSales = 0.0;
        $transferSales = 0.0;
        $debitSales = 0.0;
        $otherSales = 0.0;
        $nonCashSales = 0.0;
        $paymentBreakdown = [];

        foreach ($paidTransactions as $t) {
            $method = strtoupper(trim($t->payment_method ?? 'CASH'));
            $price = (float)$t->total_price;
            $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;

            if ($method === 'CASH' || $method === 'TUNAI') {
                $cashSales += $price;
            } elseif (str_contains($method, 'QRIS')) {
                $qrisSales += $price;
                $nonCashSales += $price;
            } elseif (str_contains($method, 'GRAB') || str_contains($method, 'GOFOOD') || str_contains($method, 'SHOPEE') || str_contains($method, 'TIKTOK')) {
                $grabSales += $price;
                $nonCashSales += $price;
            } elseif (str_contains($method, 'TRANSFER')) {
                $transferSales += $price;
                $nonCashSales += $price;
            } elseif (str_contains($method, 'DEBIT') || str_contains($method, 'EDC') || str_contains($method, 'CREDIT') || str_contains($method, 'KARTU')) {
                $debitSales += $price;
                $nonCashSales += $price;
            } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                $dpPaid = (float)($t->amount_paid ?? 0);
                if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                    $cashSales += $dpPaid;
                } else {
                    $otherSales += $dpPaid;
                    $nonCashSales += $dpPaid;
                }
            } else {
                $otherSales += $price;
                $nonCashSales += $price;
            }
        }

        $expectedCash = (float)$shift->initial_cash + $cashSales;

        return response()->json([
            'shift'              => $shift,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'cash_sales'         => $cashSales,
            'qris_sales'         => $qrisSales,
            'grab_sales'         => $grabSales,
            'transfer_sales'     => $transferSales,
            'debit_sales'        => $debitSales,
            'other_sales'        => $otherSales,
            'non_cash_sales'     => $nonCashSales,
            'payment_breakdown'  => $paymentBreakdown,
            'expected_cash'      => $expectedCash,
        ]);
    }

    /**
     * Get the most recently closed shift for an outlet (to compare initial cash vs previous real closing cash).
     */
    public function lastClosed(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ?? $user?->outlet_id);

        $query = Shift::with(['user', 'closedByUser', 'outlet', 'shiftSchedule'])
            ->where('status', 'CLOSED');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $query->where('outlet_id', $outletId);
        }

        $lastShift = $query->orderByDesc('closed_at')->orderByDesc('id')->first();

        if (!$lastShift) {
            return response()->json(null);
        }

        return response()->json([
            'id'              => $lastShift->id,
            'shift_name'      => $lastShift->shift_name,
            'outlet_id'       => $lastShift->outlet_id,
            'outlet_name'     => $lastShift->outlet?->name,
            'user_id'         => $lastShift->user_id,
            'cashier_name'    => $lastShift->user?->name,
            'closed_by'       => $lastShift->closed_by,
            'closed_by_name'  => $lastShift->closedByUser?->name ?? $lastShift->user?->name,
            'opened_at'       => $lastShift->opened_at,
            'closed_at'       => $lastShift->closed_at,
            'initial_cash'    => (float)$lastShift->initial_cash,
            'closing_cash'    => (float)$lastShift->closing_cash,
            'system_cash'     => (float)$lastShift->system_cash,
            'cash_difference' => (float)$lastShift->cash_difference,
            'notes'           => $lastShift->notes,
        ]);
    }

    /**
     * Open a new shift.
     */
    public function open(Request $request)
    {
        $data = $request->validate([
            'shift_name'        => 'nullable|string|max:100',
            'shift_schedule_id' => 'nullable|integer',
            'initial_cash'      => 'nullable|numeric|min:0',
            'notes'             => 'nullable|string|max:500',
            'outlet_id'         => 'nullable|integer',
        ]);

        $user = $request->user();
        $businessId = (int) $user->business_id;

        $outletId = !empty($data['outlet_id']) ? (int)$data['outlet_id'] : ($user->outlet_id ?? 1);

        // If user is Owner Outlet or Pegawai with assigned outlet, lock strictly to their outlet
        if (($user->isOwnerOutlet() || $user->isPegawai()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        }

        // Single-company strict validation: verify outlet belongs to this business
        $outlet = Outlet::where('id', $outletId)->where('business_id', $businessId)->first();
        if (!$outlet) {
            return response()->json([
                'message' => 'Outlet tidak valid atau tidak terdaftar dalam perusahaan Anda.'
            ], 422);
        }

        // Check if there is already an open shift for this outlet
        $existing = Shift::where('status', 'OPEN')
            ->where('business_id', $businessId)
            ->where('outlet_id', $outletId)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => "Masih ada shift yang aktif di outlet ini (#{$existing->id} - {$existing->shift_name}). Silakan closing shift tersebut terlebih dahulu.",
                'active_shift' => $existing,
            ], 422);
        }

        $shiftScheduleId = null;
        $shiftName = $data['shift_name'] ?? null;
        $isOwner = $user->isOwnerBisnis() || $user->isSuperadminPlatform();

        // 1. If explicit shift_schedule_id provided
        if (!empty($data['shift_schedule_id'])) {
            $schedule = ShiftSchedule::where('business_id', $businessId)
                ->where('outlet_id', $outletId)
                ->find($data['shift_schedule_id']);

            if (!$schedule) {
                return response()->json([
                    'message' => 'Jadwal shift yang dipilih tidak valid atau tidak sesuai dengan outlet perusahaan Anda.'
                ], 422);
            }

            // Strict enforcement: only assigned cashiers or Owners can open
            if ($schedule->is_strict && !$isOwner) {
                $assignedIds = $schedule->assigned_user_ids ?? [];
                if (!in_array($user->id, $assignedIds)) {
                    return response()->json([
                        'message' => "Akses Ditolak: Anda ({$user->name}) tidak dijadwalkan pada '{$schedule->shift_name}'. Berdasarkan pengaturan Owner, shift ini hanya boleh dibuka oleh kasir yang ditugaskan."
                    ], 403);
                }
            }

            $shiftScheduleId = $schedule->id;
            $shiftName = $schedule->shift_name;
        } else {
            // 2. If no shift_schedule_id was sent, check if shift_name matches any scheduled shift in this outlet
            if ($shiftName) {
                $matchedSchedule = ShiftSchedule::where('business_id', $businessId)
                    ->where('outlet_id', $outletId)
                    ->where('shift_name', $shiftName)
                    ->first();

                if ($matchedSchedule) {
                    if ($matchedSchedule->is_strict && !$isOwner) {
                        $assignedIds = $matchedSchedule->assigned_user_ids ?? [];
                        if (!in_array($user->id, $assignedIds)) {
                            return response()->json([
                                'message' => "Akses Ditolak: Anda ({$user->name}) tidak dijadwalkan pada '{$matchedSchedule->shift_name}'. Berdasarkan pengaturan Owner, shift ini hanya boleh dibuka oleh kasir yang ditugaskan."
                            ], 403);
                        }
                    }
                    $shiftScheduleId = $matchedSchedule->id;
                }
            } else {
                $shiftName = 'Shift 1 (Pagi)';
            }
        }

        $shift = Shift::create([
            'business_id'       => $businessId,
            'outlet_id'         => $outletId,
            'shift_schedule_id' => $shiftScheduleId,
            'shift_name'        => $shiftName,
            'user_id'           => $user->id,
            'created_by'        => $user->id,
            'opened_at'         => now(),
            'initial_cash'      => $data['initial_cash'] ?? 0,
            'system_cash'       => 0,
            'status'            => 'OPEN',
            'notes'             => $data['notes'] ?? null,
        ]);

        $shift->load(['user', 'outlet', 'creator', 'updater', 'shiftSchedule']);

        return response()->json($shift, 201);
    }

    /**
     * Get real-time summary of sales & theoretical ingredient consumption for a shift.
     */
    public function summary(Shift $shift)
    {
        $user = auth()->user() ?? request()->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk mengelola shift di cabang outlet lain.'], 403);
            }
        }

        $shift->load(['user', 'closedByUser']);

        $paidTransactions = $shift->transactions()->where('status', 'PAID')->get();
        $totalTransactions = $paidTransactions->unique(fn($t) => $t->order_number ?: ('trx_' . $t->id))->count();
        $totalSales = (float)$paidTransactions->sum('total_price');

        $cashSales = 0.0;
        $qrisSales = 0.0;
        $grabSales = 0.0;
        $transferSales = 0.0;
        $debitSales = 0.0;
        $otherSales = 0.0;
        $nonCashSales = 0.0;
        $paymentBreakdown = [];

        foreach ($paidTransactions as $t) {
            $method = strtoupper(trim($t->payment_method ?? 'CASH'));
            $price = (float)$t->total_price;
            $paymentBreakdown[$method] = ($paymentBreakdown[$method] ?? 0.0) + $price;

            if ($method === 'CASH' || $method === 'TUNAI') {
                $cashSales += $price;
            } elseif (str_contains($method, 'QRIS')) {
                $qrisSales += $price;
                $nonCashSales += $price;
            } elseif (str_contains($method, 'GRAB') || str_contains($method, 'GOFOOD') || str_contains($method, 'SHOPEE') || str_contains($method, 'TIKTOK')) {
                $grabSales += $price;
                $nonCashSales += $price;
            } elseif (str_contains($method, 'TRANSFER')) {
                $transferSales += $price;
                $nonCashSales += $price;
            } elseif (str_contains($method, 'DEBIT') || str_contains($method, 'EDC') || str_contains($method, 'CREDIT') || str_contains($method, 'KARTU')) {
                $debitSales += $price;
                $nonCashSales += $price;
            } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                $dpPaid = (float)($t->amount_paid ?? 0);
                if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                    $cashSales += $dpPaid;
                } else {
                    $otherSales += $dpPaid;
                    $nonCashSales += $dpPaid;
                }
            } else {
                $otherSales += $price;
                $nonCashSales += $price;
            }
        }

        $expectedCash = (float)$shift->initial_cash + $cashSales;

        // Group actual menus sold (exclude dummy equal split installment rows, include SPLIT_CLOSED actual items)
        $actualMenuTransactions = $shift->transactions()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->with('menu')
            ->get();

        $menuSummary = [];
        foreach ($actualMenuTransactions as $t) {
            $menuId = $t->menu_id;
            if (!isset($menuSummary[$menuId])) {
                $menuSummary[$menuId] = [
                    'menu_id'   => $menuId,
                    'menu_name' => $t->menu?->name ?? 'Menu #'.$menuId,
                    'qty'       => 0,
                    'total'     => 0.0,
                ];
            }
            $menuSummary[$menuId]['qty'] += (int)$t->qty;
            $menuSummary[$menuId]['total'] += (float)$t->total_price;
        }

        // Calculate ingredient usage
        $ingredientUsages = $shift->calculateIngredientUsage();

        // Active Open Bills on this outlet (grouped by order_number & customer_name)
        $openBills = Transaction::where('outlet_id', $shift->outlet_id)
            ->where('status', 'HOLD')
            ->select(
                'order_number',
                'customer_name',
                'notes',
                DB::raw('SUM(total_price) as total_amount'),
                DB::raw('SUM(qty) as total_items'),
                DB::raw('MIN(created_at) as created_at')
            )
            ->groupBy('order_number', 'customer_name', 'notes')
            ->get();

        $openBillsCount = $openBills->count();
        $openBillsTotal = (float)$openBills->sum('total_amount');

        $ordersGrouped = [];
        foreach ($paidTransactions as $t) {
            $ordNum = $t->order_number ?: ('TRX-' . $t->id);
            if (!isset($ordersGrouped[$ordNum])) {
                $ordersGrouped[$ordNum] = [
                    'order_number'    => $ordNum,
                    'time'            => $t->created_at ? $t->created_at->format('H:i') : '-',
                    'total_price'     => 0.0,
                    'total_items'     => 0,
                    'payment_method'  => $t->payment_method ?? 'CASH',
                    'customer_name'   => $t->customer_name ?? null,
                ];
            }
            $ordersGrouped[$ordNum]['total_price'] += (float)$t->total_price;
            $ordersGrouped[$ordNum]['total_items'] += (int)$t->qty;
        }
        $ordersList = array_values($ordersGrouped);
        $orderNumbers = array_keys($ordersGrouped);
        $orderRange = '-';
        if (count($orderNumbers) === 1) {
            $orderRange = $orderNumbers[0];
        } elseif (count($orderNumbers) > 1) {
            $orderRange = reset($orderNumbers) . ' s/d ' . end($orderNumbers);
        }

        return response()->json([
            'shift'              => $shift,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'cash_sales'         => $cashSales,
            'qris_sales'         => $qrisSales,
            'grab_sales'         => $grabSales,
            'transfer_sales'     => $transferSales,
            'debit_sales'        => $debitSales,
            'other_sales'        => $otherSales,
            'non_cash_sales'     => $nonCashSales,
            'payment_breakdown'  => $paymentBreakdown,
            'expected_cash'      => $expectedCash,
            'menus_sold'         => array_values($menuSummary),
            'ingredient_usages'  => $ingredientUsages,
            'orders'             => $ordersList,
            'order_numbers'      => $orderNumbers,
            'order_number_range' => $orderRange,
            'open_bills_count'   => $openBillsCount,
            'open_bills_total'   => $openBillsTotal,
            'open_bills'         => $openBills,
        ]);
    }

    /**
     * Close an active shift and post aggregated stock movements.
     */
    public function close(Request $request, Shift $shift)
    {
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk menutup shift di cabang outlet lain.'], 403);
            }
        }

        if ($shift->status !== 'OPEN') {
            return response()->json(['message' => 'Shift ini sudah ditutup sebelumnya.'], 422);
        }

        // Check active OPEN BILLS in HOLD status for this outlet
        $openBills = Transaction::where('outlet_id', $shift->outlet_id)
            ->where('status', 'HOLD')
            ->select(
                'order_number',
                'customer_name',
                'notes',
                DB::raw('SUM(total_price) as total_amount'),
                DB::raw('SUM(qty) as total_items')
            )
            ->groupBy('order_number', 'customer_name', 'notes')
            ->get();

        $openBillsCount = $openBills->count();
        $openBillsTotal = (float)$openBills->sum('total_amount');
        $allowCarryOver = $request->boolean('allow_carry_over');

        if ($openBillsCount > 0 && !$allowCarryOver) {
            return response()->json([
                'has_open_bills'   => true,
                'open_bills_count' => $openBillsCount,
                'open_bills_total' => $openBillsTotal,
                'open_bills'       => $openBills,
                'message'          => "Masih ada {$openBillsCount} tagihan terbuka atas nama pelanggan (Open Bill) senilai Rp " . number_format($openBillsTotal, 0, ',', '.') . " di outlet ini. Anda dapat mengalihkan tagihan ke shift berikutnya atau menyelesaikan pembayarannya terlebih dahulu.",
            ], 422);
        }

        $data = $request->validate([
            'closing_cash' => 'required|numeric|min:0',
            'notes'        => 'nullable|string|max:500',
        ]);

        $result = DB::transaction(function () use ($request, $shift, $data, $openBillsCount, $openBillsTotal, $allowCarryOver) {
            $paidTransactions = $shift->transactions()->where('status', 'PAID')->get();
            $systemSales = (float)$paidTransactions->sum('total_price');

            $cashSales = 0.0;
            $nonCashSales = 0.0;
            foreach ($paidTransactions as $t) {
                $method = strtoupper(trim($t->payment_method ?? 'CASH'));
                if ($method === 'CASH' || $method === 'TUNAI') {
                    $cashSales += (float)$t->total_price;
                } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                    $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                    $dpPaid = (float)($t->amount_paid ?? 0);
                    if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                        $cashSales += $dpPaid;
                    } else {
                        $nonCashSales += $dpPaid;
                    }
                } else {
                    $nonCashSales += (float)$t->total_price;
                }
            }

            $closingCash = (float)$data['closing_cash'];
            $expectedCash = (float)$shift->initial_cash + $cashSales;
            $cashDiff = $closingCash - $expectedCash;

            // 1. Calculate aggregated theoretical ingredient usage
            $ingredientUsages = $shift->calculateIngredientUsage();
            $createdMovements = [];

            // 2. Post 1 StockMovement per ingredient with type SALE_USAGE
            foreach ($ingredientUsages as $item) {
                if ($item['total_qty'] <= 0) continue;

                $m = StockMovement::create([
                    'date'          => now()->toDateString(),
                    'ingredient_id' => $item['ingredient_id'],
                    'type'          => 'SALE_USAGE',
                    'qty'           => $item['total_qty'],
                    'note'          => "Closing Shift #{$shift->id} ({$shift->shift_name}) - {$shift->user->name}",
                    'shift_id'      => $shift->id,
                    'outlet_id'     => $shift->outlet_id ?? $request->user()->outlet_id ?? 1,
                    'user_id'       => $request->user()->id,
                    'created_by'    => $request->user()->id,
                ]);
                $createdMovements[] = $m;
            }

            // 3. Update shift to CLOSED with carry-over notes if any
            $combinedNotes = $shift->notes;
            if ($openBillsCount > 0 && $allowCarryOver) {
                $carryNote = "Open Bill Dialihkan ke Shift Berikutnya: {$openBillsCount} tagihan pelanggan (Rp " . number_format($openBillsTotal, 0, ',', '.') . ")";
                $combinedNotes = $combinedNotes ? $combinedNotes . "\n" . $carryNote : $carryNote;
            }
            if (!empty($data['notes'])) {
                $combinedNotes = $combinedNotes ? $combinedNotes . "\nClosing: " . $data['notes'] : $data['notes'];
            }

            $shift->update([
                'status'          => 'CLOSED',
                'closed_at'       => now(),
                'system_cash'     => $systemSales,
                'closing_cash'    => $closingCash,
                'cash_difference' => $cashDiff,
                'closed_by'       => $request->user()->id,
                'updated_by'      => $request->user()->id,
                'notes'           => $combinedNotes,
            ]);

            return [
                'message'          => 'Shift berhasil ditutup.' . ($openBillsCount > 0 ? " {$openBillsCount} tagihan pelanggan dialihkan ke shift berikutnya." : ''),
                'shift'            => $shift->fresh(['user', 'closedByUser', 'creator', 'updater']),
                'movements_count'  => count($createdMovements),
                'total_sales'      => $systemSales,
                'cash_sales'       => $cashSales,
                'non_cash_sales'   => $nonCashSales,
                'expected_cash'    => $expectedCash,
                'cash_difference'  => $cashDiff,
                'carry_over_count' => $openBillsCount,
                'carry_over_total' => $openBillsTotal,
            ];
        });

        return response()->json($result);
    }

    /**
     * Get all transactions within a shift, optionally showing ingredient contribution.
     */
    public function transactions(Request $request, Shift $shift)
    {
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Anda tidak memiliki hak akses untuk melihat transaksi shift di cabang outlet lain.'], 403);
            }
        }

        $ingId = $request->ingredient_id ? (int)$request->ingredient_id : null;

        $transactions = $shift->transactions()
            ->with(['menu.recipes.items.ingredient', 'user', 'outlet', 'shift', 'creator', 'updater', 'cancelledByUser', 'voidRequestedByUser', 'voidApprovedByUser', 'voidRejectedByUser', 'modifiers.ingredient', 'discount', 'urgentNotes.ingredient', 'customer'])
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $rows = [];
        $totalUsageForIngredient = 0.0;
        $ingUnit = '';

        foreach ($transactions as $t) {
            $menu = $t->menu;
            $recipe = $menu ? ($menu->recipes->firstWhere('version', $t->recipe_version) ?: $menu->recipes->first()) : null;

            $itemUsage = 0.0;
            if ($ingId && $recipe) {
                $item = $recipe->items->firstWhere('ingredient_id', $ingId);
                if ($item) {
                    $itemUsage = (float)$item->qty * (int)$t->qty;
                    $totalUsageForIngredient += $itemUsage;
                    $ingUnit = $item->unit ?: $item->ingredient?->unit_pakai;
                }
            }

            $rows[] = [
                'id'              => $t->id,
                'order_number'    => $t->order_number,
                'time'            => $t->created_at ? $t->created_at->format('H:i') : '-',
                'date'            => $t->date,
                'menu_id'         => $t->menu_id,
                'menu_name'       => $menu?->name ?? 'Menu #'.$t->menu_id,
                'menu_price'      => (float)($menu?->price ?? 0),
                'qty'             => (int)$t->qty,
                'total_price'     => (float)$t->total_price,
                'user_name'       => $t->user?->name ?? 'Kasir',
                'payment_method'  => $t->payment_method ?? 'CASH',
                'status'          => $t->status ?? 'PAID',
                'ingredient_usage'=> $ingId ? round($itemUsage, 3) : null,
                'ingredient_unit' => $ingId ? $ingUnit : null,
            ];
        }

        $totalOrders = $transactions->unique(fn($t) => $t->order_number ?: ('trx_' . $t->id))->count();

        return response()->json([
            'shift'                      => $shift->load(['user', 'closedByUser', 'outlet']),
            'ingredient_id'              => $ingId,
            'total_ingredient_usage'     => round($totalUsageForIngredient, 3),
            'ingredient_unit'            => $ingUnit,
            'total_transactions'         => $totalOrders,
            'total_items'                => count($rows),
            'total_sales'                => array_sum(array_column($rows, 'total_price')),
            'transactions'               => $rows,
            'raw_transactions'           => $transactions,
        ]);
    }

    /**
     * Get receipt data for printing shift closing report.
     * Matches the thermal receipt format: outlet header, items sold, payment breakdown, expenses, totals.
     */
    public function receipt(Request $request, Shift $shift)
    {
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            if ((int)$shift->outlet_id !== (int)$user->outlet_id) {
                return response()->json(['message' => 'Akses ditolak.'], 403);
            }
        }

        $shift->load(['user', 'closedByUser', 'outlet']);

        // Outlet & Business info for receipt header
        $outlet = $shift->outlet;
        $business = $outlet ? \App\Models\Business::find($outlet->business_id) : null;

        // Get all PAID transactions (exclude dummy EQUAL split rows, include SPLIT_CLOSED actual items)
        $paidTransactions = $shift->transactions()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->with('menu')
            ->orderBy('created_at', 'asc')
            ->get();

        // Items sold grouped by menu
        $itemsSold = [];
        foreach ($paidTransactions as $t) {
            $menuId = $t->menu_id;
            $menuName = $t->menu?->name ?? 'Menu #' . $menuId;
            if (!isset($itemsSold[$menuId])) {
                $itemsSold[$menuId] = [
                    'menu_name' => $menuName,
                    'qty'       => 0,
                    'total'     => 0.0,
                ];
            }
            $itemsSold[$menuId]['qty'] += (int)$t->qty;
            $itemsSold[$menuId]['total'] += (float)$t->total_price;
        }

        // Payment method breakdown
        $allPaidTransactions = $shift->transactions()->where('status', 'PAID')->get();
        $paymentBreakdown = [];
        $cashTotal = 0.0;
        $nonCashTotal = 0.0;

        foreach ($allPaidTransactions as $t) {
            $method = strtoupper($t->payment_method ?? 'CASH');
            if (!isset($paymentBreakdown[$method])) {
                $paymentBreakdown[$method] = 0.0;
            }
            $paymentBreakdown[$method] += (float)$t->total_price;

            if ($method === 'CASH' || $method === 'TUNAI') {
                $cashTotal += (float)$t->total_price;
            } elseif (in_array($method, ['KASBON', 'PIUTANG'])) {
                $dpMethod = strtoupper(trim($t->dp_payment_method ?? 'CASH'));
                $dpPaid = (float)($t->amount_paid ?? 0);
                if ($dpMethod === 'CASH' || $dpMethod === 'TUNAI') {
                    $cashTotal += $dpPaid;
                } else {
                    $nonCashTotal += $dpPaid;
                }
            } else {
                $nonCashTotal += (float)$t->total_price;
            }
        }

        // Operating expenses during shift period (if any)
        $expenses = [];
        if ($shift->opened_at) {
            $expenseQuery = \App\Models\OperatingExpense::where('outlet_id', $shift->outlet_id)
                ->where('business_id', $shift->business_id)
                ->where('date', '>=', $shift->opened_at->toDateString());

            if ($shift->closed_at) {
                $expenseQuery->where('date', '<=', $shift->closed_at->toDateString());
            }

            $expenseQuery->where('created_at', '>=', $shift->opened_at);
            if ($shift->closed_at) {
                $expenseQuery->where('created_at', '<=', $shift->closed_at);
            }

            $expenses = $expenseQuery->get()->map(function ($e) {
                return [
                    'description' => $e->description,
                    'amount'      => (float)$e->amount,
                ];
            })->toArray();
        }

        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        $totalSales = (float)$allPaidTransactions->sum('total_price');
        $totalTransactions = $allPaidTransactions->unique(fn($t) => $t->order_number ?: ('trx_' . $t->id))->count();

        // Group all transactions by order_number for order list on receipt
        $ordersGrouped = [];
        foreach ($paidTransactions as $t) {
            $ordNum = $t->order_number ?: ('TRX-' . $t->id);
            if (!isset($ordersGrouped[$ordNum])) {
                $ordersGrouped[$ordNum] = [
                    'order_number'    => $ordNum,
                    'time'            => $t->created_at ? $t->created_at->format('H:i') : '-',
                    'total_price'     => 0.0,
                    'total_items'     => 0,
                    'payment_method'  => $t->payment_method ?? 'CASH',
                    'customer_name'   => $t->customer_name ?? null,
                ];
            }
            $ordersGrouped[$ordNum]['total_price'] += (float)$t->total_price;
            $ordersGrouped[$ordNum]['total_items'] += (int)$t->qty;
        }
        $ordersList = array_values($ordersGrouped);
        $orderNumbers = array_keys($ordersGrouped);
        $orderRange = '-';
        if (count($orderNumbers) === 1) {
            $orderRange = $orderNumbers[0];
        } elseif (count($orderNumbers) > 1) {
            $orderRange = reset($orderNumbers) . ' s/d ' . end($orderNumbers);
        }

        return response()->json([
            'outlet_name'        => $outlet?->name ?? 'Outlet',
            'outlet_address'     => $outlet?->address ?? '',
            'outlet_phone'       => $outlet?->phone ?? '',
            'business_name'      => $business?->name ?? '',
            'shift'              => $shift,
            'kasir_name'         => $shift->user?->name ?? 'Kasir',
            'closed_by_name'     => $shift->closedByUser?->name ?? $shift->user?->name ?? 'Kasir',
            'items_sold'         => array_values($itemsSold),
            'orders'             => $ordersList,
            'order_numbers'      => $orderNumbers,
            'order_number_range' => $orderRange,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'payment_breakdown'  => $paymentBreakdown,
            'cash_total'         => $cashTotal,
            'non_cash_total'     => $nonCashTotal,
            'non_cash_details'   => collect($paymentBreakdown)->filter(function ($v, $k) {
                return !in_array($k, ['CASH', 'TUNAI']);
            })->toArray(),
            'expenses'           => $expenses,
            'total_expenses'     => $totalExpenses,
            'net_amount'         => $totalSales - $totalExpenses,
            'initial_cash'       => (float)$shift->initial_cash,
            'system_cash'        => $totalSales,
            'closing_cash'       => $shift->closing_cash !== null ? (float)$shift->closing_cash : null,
            'cash_difference'    => $shift->cash_difference !== null ? (float)$shift->cash_difference : null,
        ]);
    }
}
