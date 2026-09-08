<?php

namespace App\Http\Controllers;

use App\Models\Shift;
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
        $query = Shift::with(['user', 'closedByUser', 'creator', 'updater', 'outlet'])
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
        $query = Shift::with(['user', 'outlet'])->where('status', 'OPEN');
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
            'shift_name'   => 'required|string|max:100',
            'initial_cash' => 'nullable|numeric|min:0',
            'notes'        => 'nullable|string|max:500',
            'outlet_id'    => 'nullable|exists:outlets,id',
        ]);

        $outletId = !empty($data['outlet_id']) ? (int)$data['outlet_id'] : ($request->user()->outlet_id ?? 1);

        // If user is Owner Outlet or Pegawai with assigned outlet, lock to their outlet
        if ($request->user()->isOwnerOutlet() && $request->user()->outlet_id) {
            $outletId = (int)$request->user()->outlet_id;
        }

        // Check if there is already an open shift for this outlet
        $existing = Shift::where('status', 'OPEN')
            ->where('outlet_id', $outletId)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => "Masih ada shift yang aktif di outlet ini (#{$existing->id} - {$existing->shift_name}). Silakan closing shift tersebut terlebih dahulu.",
                'active_shift' => $existing,
            ], 422);
        }

        $shift = Shift::create([
            'shift_name'   => $data['shift_name'],
            'user_id'      => $request->user()->id,
            'created_by'   => $request->user()->id,
            'opened_at'    => now(),
            'initial_cash' => $data['initial_cash'] ?? 0,
            'system_cash'  => 0,
            'status'       => 'OPEN',
            'notes'        => $data['notes'] ?? null,
            'outlet_id'    => $outletId,
        ]);

        $shift->load(['user', 'outlet', 'creator', 'updater']);

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

        return response()->json([
            'shift'              => $shift,
            'total_transactions' => $totalTransactions,
            'total_sales'        => $totalSales,
            'expected_cash'      => (float)$shift->initial_cash + $totalSales,
            'menus_sold'         => array_values($menuSummary),
            'ingredient_usages'  => $ingredientUsages,
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

        // Prevent closing if there are still active OPEN BILLS in HOLD status
        $openBillsCount = Transaction::where('outlet_id', $shift->outlet_id)
            ->where('status', 'HOLD')
            ->distinct('order_number')
            ->count('order_number');

        if ($openBillsCount > 0) {
            return response()->json([
                'message' => "Masih ada {$openBillsCount} tagihan terbuka / meja terisi (Open Bill) di outlet ini. Harap selesaikan pembayaran atau batalkan tagihan tersebut sebelum closing shift.",
            ], 422);
        }

        $data = $request->validate([
            'closing_cash' => 'required|numeric|min:0',
            'notes'        => 'nullable|string|max:500',
        ]);

        $result = DB::transaction(function () use ($request, $shift, $data) {
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

            // 3. Update shift to CLOSED
            $combinedNotes = $shift->notes;
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
                'shift'           => $shift->fresh(['user', 'closedByUser', 'creator', 'updater']),
                'movements_count' => count($createdMovements),
                'total_sales'     => $systemSales,
                'cash_difference' => $cashDiff,
            ];
        });

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
