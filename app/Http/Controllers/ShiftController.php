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
        $query = Shift::with(['user', 'closedByUser', 'creator', 'updater', 'outlet', 'shiftSchedule'])
            ->withCount('transactions')
            ->orderByDesc('opened_at')
            ->orderByDesc('id');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->outlet_id) {
            $query->where('outlet_id', $request->outlet_id);
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

        return response()->json($query->limit(100)->get());
    }

    /**
     * Get currently active / open shift.
     */
    public function active(Request $request)
    {
        $outletId = $request->outlet_id ?? $request->user()?->outlet_id;
        $query = Shift::with(['user', 'outlet', 'shiftSchedule'])->where('status', 'OPEN');
        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }
        $shift = $query->orderByDesc('opened_at')->first();

        if (!$shift) {
            return response()->json(null);
        }

        $totalTransactions = $shift->transactions()->where('status', 'PAID')->count();
        $totalSales = (float)$shift->transactions()->where('status', 'PAID')->sum('total_price');

        return response()->json([
            'shift'              => $shift,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'expected_cash'      => (float)$shift->initial_cash + $totalSales,
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

        // If user is Owner Outlet or Pegawai with assigned outlet, lock to their outlet
        if ($user->isOwnerOutlet() && $user->outlet_id) {
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
        $shift->load(['user', 'closedByUser']);

        $paidTransactions = $shift->transactions()->where('status', 'PAID')->get();
        $totalTransactions = $paidTransactions->count();
        $totalSales = (float)$paidTransactions->sum('total_price');

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

        return response()->json([
            'shift'              => $shift,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'expected_cash'      => (float)$shift->initial_cash + $totalSales,
            'menus_sold'         => array_values($menuSummary),
            'ingredient_usages'  => $ingredientUsages,
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
            $systemSales = (float)$shift->transactions()->where('status', 'PAID')->sum('total_price');
            $closingCash = (float)$data['closing_cash'];
            $expectedCash = (float)$shift->initial_cash + $systemSales;
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
                'cash_difference'  => $cashDiff,
                'carry_over_count' => $openBillsCount,
                'carry_over_total' => $openBillsTotal,
            ];
        });

        return response()->json($result);

        return response()->json($result);
    }

    /**
     * Get all transactions within a shift, optionally showing ingredient contribution.
     */
    public function transactions(Request $request, Shift $shift)
    {
        $ingId = $request->ingredient_id ? (int)$request->ingredient_id : null;

        $transactions = $shift->transactions()
            ->with(['menu.recipes.items.ingredient', 'user'])
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
                'time'            => $t->created_at ? $t->created_at->format('H:i') : '-',
                'date'            => $t->date,
                'menu_id'         => $t->menu_id,
                'menu_name'       => $menu?->name ?? 'Menu #'.$t->menu_id,
                'menu_price'      => (float)($menu?->price ?? 0),
                'qty'             => (int)$t->qty,
                'total_price'     => (float)$t->total_price,
                'user_name'       => $t->user?->name ?? 'Kasir',
                'ingredient_usage'=> $ingId ? round($itemUsage, 3) : null,
                'ingredient_unit' => $ingId ? $ingUnit : null,
            ];
        }

        return response()->json([
            'shift'                      => $shift->load(['user', 'closedByUser']),
            'ingredient_id'              => $ingId,
            'total_ingredient_usage'     => round($totalUsageForIngredient, 3),
            'ingredient_unit'            => $ingUnit,
            'total_transactions'         => count($rows),
            'total_sales'                => array_sum(array_column($rows, 'total_price')),
            'transactions'               => $rows,
        ]);
    }
}
