<?php

namespace App\Http\Controllers;

use App\Models\WasteLog;
use App\Models\Ingredient;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WasteController extends Controller
{
    /**
     * Display a listing of waste logs with filters.
     */
    public function index(Request $request)
    {
        $query = WasteLog::with(['ingredient', 'outlet', 'shift', 'user', 'creator', 'stockMovement'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('from')) {
            $query->where('date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('date', '<=', $request->to);
        }

        if ($request->filled('reason_category') && $request->reason_category !== 'ALL') {
            $query->where('reason_category', $request->reason_category);
        }

        if ($request->filled('ingredient_id')) {
            $query->where('ingredient_id', $request->ingredient_id);
        }

        return response()->json($query->limit(300)->get());
    }

    /**
     * Get aggregate analytics & KPI for waste and loss cost.
     */
    public function analytics(Request $request)
    {
        $query = WasteLog::query();

        if ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('from')) {
            $query->where('date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('date', '<=', $request->to);
        }

        $logs = $query->with('ingredient')->get();

        $totalLossCost = (float)$logs->sum('loss_cost');
        $totalEntries = $logs->count();
        $totalQtyPakai = (float)$logs->sum('qty_pakai');

        // Breakdown by reason category
        $reasonCategories = WasteLog::reasonCategories();
        $byReasonGroup = $logs->groupBy('reason_category');
        $byReason = [];

        foreach ($reasonCategories as $key => $label) {
            $group = $byReasonGroup->get($key, collect());
            $cost = (float)$group->sum('loss_cost');
            $count = $group->count();
            $percent = $totalLossCost > 0 ? round(($cost / $totalLossCost) * 100, 1) : 0;

            $byReason[] = [
                'category'   => $key,
                'label'      => $label,
                'loss_cost'  => $cost,
                'count'      => $count,
                'percentage' => $percent,
            ];
        }

        // Sort reasons by loss cost desc
        usort($byReason, fn($a, $b) => $b['loss_cost'] <=> $a['loss_cost']);

        // Top 5 most costly wasted ingredients
        $byIngredientGroup = $logs->groupBy('ingredient_id');
        $topIngredients = [];

        foreach ($byIngredientGroup as $ingId => $ingLogs) {
            $ing = $ingLogs->first()->ingredient;
            $cost = (float)$ingLogs->sum('loss_cost');
            $qty = (float)$ingLogs->sum('qty_pakai');

            $topIngredients[] = [
                'ingredient_id'   => $ingId,
                'ingredient_name' => $ing?->name ?? "Bahan #{$ingId}",
                'unit'            => $ing?->unit_pakai ?? 'satuan',
                'loss_cost'       => $cost,
                'total_qty'       => $qty,
                'entries_count'   => $ingLogs->count(),
            ];
        }

        usort($topIngredients, fn($a, $b) => $b['loss_cost'] <=> $a['loss_cost']);
        $topIngredients = array_slice($topIngredients, 0, 5);

        return response()->json([
            'total_loss_cost' => $totalLossCost,
            'total_entries'   => $totalEntries,
            'total_qty_pakai' => $totalQtyPakai,
            'by_reason'       => $byReason,
            'top_ingredients' => $topIngredients,
        ]);
    }

    /**
     * Store a new waste log and automatically deduct stock via StockMovement.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'            => 'required|date',
            'ingredient_id'   => 'required|exists:ingredients,id',
            'qty'             => 'required|numeric|min:0.001',
            'unit_type'       => 'required|in:BELI,PAKAI',
            'reason_category' => 'required|string|max:50',
            'notes'           => 'nullable|string|max:500',
            'action_taken'    => 'nullable|string|max:255',
            'outlet_id'       => 'nullable|exists:outlets,id',
            'shift_id'        => 'nullable|exists:shifts,id',
        ]);

        $outletId = $data['outlet_id'] ?? $request->user()->outlet_id ?? 1;
        $ingredient = Ingredient::findOrFail($data['ingredient_id']);
        $konversi = max((float)$ingredient->konversi, 1);

        // Calculate quantities and unit costs
        $isUnitBeli = ($data['unit_type'] === 'BELI');
        $qtyPakai = $isUnitBeli ? round((float)$data['qty'] * $konversi, 3) : (float)$data['qty'];
        
        $costPerPakai = (float)$ingredient->harga / $konversi;
        if ($costPerPakai <= 0 && (float)$ingredient->last_purchase_price > 0) {
            $costPerPakai = (float)$ingredient->last_purchase_price / $konversi;
        }

        $lossCost = round($qtyPakai * $costPerPakai, 2);

        // Generate unique waste reference number: WST-YYYYMMDD-XXXX
        $datePrefix = date('Ymd', strtotime($data['date']));
        $latest = WasteLog::where('waste_no', 'like', "WST-{$datePrefix}-%")
            ->orderByDesc('id')
            ->first();

        $nextSeq = 1;
        if ($latest && preg_match("/WST-{$datePrefix}-(\d+)/", $latest->waste_no, $matches)) {
            $nextSeq = ((int)$matches[1]) + 1;
        }
        $wasteNo = sprintf("WST-%s-%04d", $datePrefix, $nextSeq);

        $categories = WasteLog::reasonCategories();
        $reasonLabel = $categories[$data['reason_category']] ?? $data['reason_category'];

        $wasteLog = DB::transaction(function () use (
            $data, $outletId, $ingredient, $qtyPakai, $costPerPakai, $lossCost, $wasteNo, $reasonLabel, $request
        ) {
            // 1. Create StockMovement of type WASTE (automatically subtracts stock in signedQty())
            $movement = StockMovement::create([
                'date'          => $data['date'],
                'ingredient_id' => $ingredient->id,
                'outlet_id'     => $outletId,
                'type'          => 'WASTE',
                'waste_reason'  => $data['reason_category'],
                'qty'           => $qtyPakai,
                'unit_price'    => $costPerPakai,
                'total_price'   => $lossCost,
                'cost_before'   => $costPerPakai,
                'cost_after'    => $costPerPakai,
                'note'          => "Waste [{$wasteNo}] {$reasonLabel}" . (!empty($data['notes']) ? " — {$data['notes']}" : ''),
                'shift_id'      => $data['shift_id'] ?? null,
                'user_id'       => $request->user()->id,
                'created_by'    => $request->user()->id,
            ]);

            // 2. Create WasteLog record
            $log = WasteLog::create([
                'waste_no'          => $wasteNo,
                'date'              => $data['date'],
                'ingredient_id'     => $ingredient->id,
                'outlet_id'         => $outletId,
                'shift_id'          => $data['shift_id'] ?? null,
                'qty'               => (float)$data['qty'],
                'unit_type'         => $data['unit_type'],
                'qty_pakai'         => $qtyPakai,
                'cost_per_unit'     => $costPerPakai,
                'loss_cost'         => $lossCost,
                'reason_category'   => $data['reason_category'],
                'notes'             => $data['notes'] ?? null,
                'action_taken'      => $data['action_taken'] ?? null,
                'user_id'           => $request->user()->id,
                'stock_movement_id' => $movement->id,
                'created_by'        => $request->user()->id,
            ]);

            return $log;
        });

        $wasteLog->load(['ingredient', 'outlet', 'shift', 'user', 'creator', 'stockMovement']);
        return response()->json($wasteLog, 201);
    }

    /**
     * Delete / Cancel a waste log and reverse stock movement.
     */
    public function destroy(WasteLog $wasteLog)
    {
        DB::transaction(function () use ($wasteLog) {
            if ($wasteLog->stock_movement_id) {
                StockMovement::where('id', $wasteLog->stock_movement_id)->delete();
            }
            $wasteLog->delete();
        });

        return response()->json([
            'message' => "Catatan bahan terbuang {$wasteLog->waste_no} berhasil dibatalkan dan stok dikembalikan.",
        ]);
    }
}
