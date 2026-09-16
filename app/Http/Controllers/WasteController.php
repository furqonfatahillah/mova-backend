<?php

namespace App\Http\Controllers;

use App\Models\WasteLog;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\OutletMenu;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WasteController extends Controller
{
    /**
     * Display a listing of waste logs with filters.
     */
    public function index(Request $request)
    {
        $query = WasteLog::with(['ingredient', 'menu', 'outlet', 'shift', 'user', 'creator', 'stockMovement'])
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

        if ($request->filled('item_type') && in_array($request->item_type, ['INGREDIENT', 'MENU'])) {
            $query->where('item_type', $request->item_type);
        }

        if ($request->filled('reason_category') && $request->reason_category !== 'ALL') {
            $query->where('reason_category', $request->reason_category);
        }

        if ($request->filled('ingredient_id')) {
            $query->where('ingredient_id', $request->ingredient_id);
        }

        if ($request->filled('menu_id')) {
            $query->where('menu_id', $request->menu_id);
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

        $logs = $query->with(['ingredient', 'menu'])->get();

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

        usort($byReason, fn($a, $b) => $b['loss_cost'] <=> $a['loss_cost']);

        // Top 5 most costly wasted items (ingredients or menus)
        $byItemGroup = $logs->groupBy(fn($l) => $l->item_type === 'MENU' ? "MENU_{$l->menu_id}" : "ING_{$l->ingredient_id}");
        $topIngredients = [];

        foreach ($byItemGroup as $key => $itemLogs) {
            $first = $itemLogs->first();
            $name = $first->item_name;
            $unit = $first->unit_pakai ?? 'satuan';
            $cost = (float)$itemLogs->sum('loss_cost');
            $qty = (float)$itemLogs->sum('qty_pakai');

            $topIngredients[] = [
                'ingredient_id'   => $first->ingredient_id,
                'menu_id'         => $first->menu_id,
                'item_type'       => $first->item_type,
                'ingredient_name' => $name,
                'unit'            => $unit,
                'loss_cost'       => $cost,
                'total_qty'       => $qty,
                'entries_count'   => $itemLogs->count(),
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
     * Store a new waste log and automatically deduct stock via StockMovement / Menu stock.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'date'            => 'required|date',
            'item_type'       => 'nullable|string|in:INGREDIENT,MENU',
            'ingredient_id'   => 'nullable|required_if:item_type,INGREDIENT|exists:ingredients,id',
            'menu_id'         => 'nullable|required_if:item_type,MENU|exists:menus,id',
            'qty'             => 'required|numeric|min:0.001',
            'unit_type'       => 'nullable|string|in:BELI,PAKAI',
            'reason_category' => 'required|string|max:50',
            'notes'           => 'nullable|string|max:500',
            'action_taken'    => 'nullable|string|max:255',
            'outlet_id'       => 'nullable|exists:outlets,id',
            'shift_id'        => 'nullable|exists:shifts,id',
        ]);

        $itemType = $data['item_type'] ?? ($request->filled('menu_id') ? 'MENU' : 'INGREDIENT');
        $outletId = $data['outlet_id'] ?? $request->user()->outlet_id ?? 1;

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
            $data, $itemType, $outletId, $wasteNo, $reasonLabel, $request
        ) {
            $movementId = null;
            $qtyPakai = (float)$data['qty'];
            $costPerUnit = 0;

            if ($itemType === 'MENU') {
                $menu = Menu::with(['recipes.items.ingredient', 'bundleItems.bundledMenu', 'bundleItems.ingredient'])->findOrFail($data['menu_id']);
                
                // Determine Menu cost price (HPP or Price)
                $costPerUnit = (float)($menu->cost_price > 0 ? $menu->cost_price : ($menu->hpp > 0 ? $menu->hpp : $menu->price));
                $lossCost = round($qtyPakai * $costPerUnit, 2);

                // Reduce stock based on Menu item type
                if ($menu->item_type === 'DIRECT') {
                    // Deduct direct retail menu stock
                    $menu->stock = max(0, (float)$menu->stock - $qtyPakai);
                    $menu->save();

                    if ($outletId && Schema::hasTable('outlet_menus')) {
                        $om = OutletMenu::firstOrNew(['outlet_id' => $outletId, 'menu_id' => $menu->id]);
                        $om->stock = max(0, (float)($om->stock ?? 0) - $qtyPakai);
                        $om->save();
                    }
                } elseif ($menu->item_type === 'RECIPE') {
                    // Deduct ingredients for active recipe
                    $recipe = $menu->recipes->sortByDesc('version')->first();
                    if ($recipe && $recipe->items) {
                        foreach ($recipe.items as $rItem) {
                            $ingCost = (float)($rItem->ingredient?->harga / max((float)($rItem->ingredient?->konversi ?? 1), 1));
                            $ingQty = (float)$rItem->qty * $qtyPakai;
                            
                            $mov = StockMovement::create([
                                'date'          => $data['date'],
                                'ingredient_id' => $rItem->ingredient_id,
                                'outlet_id'     => $outletId,
                                'type'          => 'WASTE',
                                'waste_reason'  => $data['reason_category'],
                                'qty'           => $ingQty,
                                'unit_price'    => $ingCost,
                                'total_price'   => round($ingQty * $ingCost, 2),
                                'note'          => "Waste Menu [{$menu->name} x {$qtyPakai}] {$reasonLabel}" . (!empty($data['notes']) ? " — {$data['notes']}" : ''),
                                'shift_id'      => $data['shift_id'] ?? null,
                                'user_id'       => $request->user()->id,
                                'created_by'    => $request->user()->id,
                            ]);
                            if (!$movementId) $movementId = $mov->id;
                        }
                    }
                } elseif ($menu->item_type === 'BUNDLE') {
                    // Deduct items inside bundle
                    if ($menu->bundleItems) {
                        foreach ($menu->bundleItems as $bItem) {
                            $bQty = (float)$bItem->qty * $qtyPakai;
                            if ($bItem->ingredient_id) {
                                StockMovement::create([
                                    'date'          => $data['date'],
                                    'ingredient_id' => $bItem->ingredient_id,
                                    'outlet_id'     => $outletId,
                                    'type'          => 'WASTE',
                                    'waste_reason'  => $data['reason_category'],
                                    'qty'           => $bQty,
                                    'note'          => "Waste Paket Bundling [{$menu->name} x {$qtyPakai}] {$reasonLabel}",
                                    'shift_id'      => $data['shift_id'] ?? null,
                                    'user_id'       => $request->user()->id,
                                    'created_by'    => $request->user()->id,
                                ]);
                            }
                        }
                    }
                }

                $log = WasteLog::create([
                    'waste_no'          => $wasteNo,
                    'date'              => $data['date'],
                    'item_type'         => 'MENU',
                    'menu_id'           => $menu->id,
                    'ingredient_id'     => null,
                    'outlet_id'         => $outletId,
                    'shift_id'          => $data['shift_id'] ?? null,
                    'qty'               => (float)$data['qty'],
                    'unit_type'         => 'PAKAI',
                    'qty_pakai'         => $qtyPakai,
                    'cost_per_unit'     => $costPerUnit,
                    'loss_cost'         => $lossCost,
                    'reason_category'   => $data['reason_category'],
                    'notes'             => $data['notes'] ?? null,
                    'action_taken'      => $data['action_taken'] ?? null,
                    'user_id'           => $request->user()->id,
                    'stock_movement_id' => $movementId,
                    'created_by'        => $request->user()->id,
                ]);

                return $log;
            } else {
                // INGREDIENT Waste
                $ingredient = Ingredient::findOrFail($data['ingredient_id']);
                $konversi = max((float)$ingredient->konversi, 1);

                $unitType = $data['unit_type'] ?? 'PAKAI';
                $isUnitBeli = ($unitType === 'BELI');
                $qtyPakai = $isUnitBeli ? round((float)$data['qty'] * $konversi, 3) : (float)$data['qty'];
                
                $costPerPakai = (float)$ingredient->harga / $konversi;
                if ($costPerPakai <= 0 && (float)$ingredient->last_purchase_price > 0) {
                    $costPerPakai = (float)$ingredient->last_purchase_price / $konversi;
                }

                $lossCost = round($qtyPakai * $costPerPakai, 2);

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

                $log = WasteLog::create([
                    'waste_no'          => $wasteNo,
                    'date'              => $data['date'],
                    'item_type'         => 'INGREDIENT',
                    'ingredient_id'     => $ingredient->id,
                    'menu_id'           => null,
                    'outlet_id'         => $outletId,
                    'shift_id'          => $data['shift_id'] ?? null,
                    'qty'               => (float)$data['qty'],
                    'unit_type'         => $unitType,
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
            }
        });

        $wasteLog->load(['ingredient', 'menu', 'outlet', 'shift', 'user', 'creator', 'stockMovement']);
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
            'message' => "Catatan terbuang {$wasteLog->waste_no} berhasil dibatalkan.",
        ]);
    }
}
