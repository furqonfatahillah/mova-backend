<?php

namespace App\Http\Controllers;

use App\Models\BatchPrep;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\PrepRecipe;
use App\Models\PrepRecipeItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchPrepController extends Controller
{
    // ==========================================
    // SUB-RECIPE MANAGEMENT
    // ==========================================

    /**
     * List all sub-recipes (prep recipes)
     */
    public function indexRecipes(Request $request)
    {
        $query = PrepRecipe::with([
            'ingredient',
            'items.ingredient',
            'creator',
            'updater',
        ])->orderBy('name');

        if ($request->filled('ingredient_id')) {
            $query->where('ingredient_id', $request->ingredient_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhereHas('ingredient', function($qi) use ($s) {
                      $qi->where('name', 'like', "%{$s}%")
                         ->orWhere('code', 'like', "%{$s}%");
                  });
            });
        }

        return response()->json($query->get());
    }

    /**
     * Create or update sub-recipe for a semi-finished ingredient
     * If ingredient_id is omitted, automatically creates a new SEMI_FINISHED ingredient in Master Bahan!
     */
    public function storeRecipe(Request $request)
    {
        $data = $request->validate([
            'ingredient_id' => 'nullable|exists:ingredients,id',
            'code'          => 'nullable|string|max:20',
            'name'          => 'required|string|max:255',
            'category'      => 'nullable|string|max:100',
            'output_qty'    => 'required|numeric|min:0.001',
            'output_unit'   => 'required|string|max:20',
            'stok_min'      => 'nullable|numeric|min:0',
            'notes'         => 'nullable|string',
            'items'         => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|exists:ingredients,id',
            'items.*.qty'           => 'required|numeric|min:0.0001',
            'items.*.unit'          => 'required|string|max:20',
            'items.*.waste_std'     => 'nullable|numeric|min:0|max:100',
        ]);

        return DB::transaction(function () use ($data, $request) {
            if (!empty($data['ingredient_id'])) {
                $ingredient = Ingredient::findOrFail($data['ingredient_id']);
                $ingredient->type = 'SEMI_FINISHED';
                $ingredient->yield_qty = $data['output_qty'];
                $ingredient->yield_unit = $data['output_unit'];
                if (isset($data['name'])) $ingredient->name = $data['name'];
                if (isset($data['category'])) $ingredient->category = $data['category'];
                if (isset($data['stok_min'])) $ingredient->stok_min = $data['stok_min'];
                $ingredient->save();
            } else {
                // Generate a unique code for the new Semi-Finished ingredient
                $code = $data['code'] ?? null;
                if (!$code) {
                    $lastIng = Ingredient::orderBy('id', 'desc')->first();
                    $nextNum = $lastIng ? ($lastIng->id + 1) : 1;
                    $code = 'PRP-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                    while (Ingredient::where('code', $code)->exists()) {
                        $nextNum++;
                        $code = 'PRP-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                    }
                }

                $ingredient = Ingredient::create([
                    'code'        => $code,
                    'name'        => $data['name'],
                    'category'    => $data['category'] ?? 'Bahan Olahan',
                    'type'        => 'SEMI_FINISHED',
                    'unit_beli'   => $data['output_unit'],
                    'unit_pakai'  => $data['output_unit'],
                    'konversi'    => 1,
                    'harga'       => 0,
                    'stok_awal'   => 0,
                    'stok_min'    => $data['stok_min'] ?? 0,
                    'tolerance'   => 0,
                    'yield_qty'   => $data['output_qty'],
                    'yield_unit'  => $data['output_unit'],
                    'active'      => true,
                    'created_by'  => $request->user()?->id,
                ]);
            }

            // Find existing prep recipe or create new
            $prepRecipe = PrepRecipe::where('ingredient_id', $ingredient->id)->first();
            if (!$prepRecipe) {
                $prepRecipe = new PrepRecipe();
                $prepRecipe->ingredient_id = $ingredient->id;
                $prepRecipe->created_by = $request->user()?->id;
            }

            $prepRecipe->name = $data['name'];
            $prepRecipe->output_qty = $data['output_qty'];
            $prepRecipe->output_unit = $data['output_unit'];
            $prepRecipe->notes = $data['notes'] ?? null;
            $prepRecipe->updated_by = $request->user()?->id;
            $prepRecipe->save();

            // Delete old items and insert fresh
            $prepRecipe->items()->delete();

            $totalEstimatedCost = 0;
            foreach ($data['items'] as $it) {
                PrepRecipeItem::create([
                    'prep_recipe_id' => $prepRecipe->id,
                    'ingredient_id'  => $it['ingredient_id'],
                    'qty'            => $it['qty'],
                    'unit'           => $it['unit'],
                    'waste_std'      => $it['waste_std'] ?? 0,
                ]);

                // Calculate estimated cost
                $rawIng = Ingredient::find($it['ingredient_id']);
                if ($rawIng) {
                    $konv = max((float)$rawIng->konversi, 1);
                    $costPerPakai = (float)$rawIng->harga / $konv;
                    $isBeli = (strtolower($it['unit']) === strtolower($rawIng->unit_beli));
                    $baseQty = (float)$it['qty'];
                    $qtyPakai = $isBeli ? ($baseQty * $konv) : $baseQty;
                    $wasteFactor = 1 + ((float)($it['waste_std'] ?? 0) / 100);
                    $totalEstimatedCost += ($qtyPakai * $costPerPakai * $wasteFactor);
                }
            }

            // Update initial unit cost on the semi-finished ingredient if harga is 0
            if ((float)$ingredient->harga == 0 && (float)$data['output_qty'] > 0) {
                $ingredient->harga = round($totalEstimatedCost / (float)$data['output_qty'], 2);
                $ingredient->save();
            }

            $prepRecipe->load(['ingredient', 'items.ingredient', 'creator', 'updater']);
            return response()->json($prepRecipe, 201);
        });
    }

    /**
     * Show single sub-recipe
     */
    public function showRecipe(PrepRecipe $prepRecipe)
    {
        $prepRecipe->load(['ingredient', 'items.ingredient', 'creator', 'updater']);
        return response()->json($prepRecipe);
    }

    /**
     * Update sub-recipe
     */
    public function updateRecipe(Request $request, PrepRecipe $prepRecipe)
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'output_qty'  => 'sometimes|numeric|min:0.001',
            'output_unit' => 'sometimes|string|max:20',
            'notes'       => 'nullable|string',
            'items'       => 'sometimes|array|min:1',
            'items.*.ingredient_id' => 'required|exists:ingredients,id',
            'items.*.qty'           => 'required|numeric|min:0.0001',
            'items.*.unit'          => 'required|string|max:20',
            'items.*.waste_std'     => 'nullable|numeric|min:0|max:100',
        ]);

        return DB::transaction(function () use ($data, $request, $prepRecipe) {
            if (isset($data['name'])) $prepRecipe->name = $data['name'];
            if (isset($data['output_qty'])) $prepRecipe->output_qty = $data['output_qty'];
            if (isset($data['output_unit'])) $prepRecipe->output_unit = $data['output_unit'];
            if (array_key_exists('notes', $data)) $prepRecipe->notes = $data['notes'];
            $prepRecipe->updated_by = $request->user()?->id;
            $prepRecipe->save();

            // Update associated ingredient yield info if changed
            if ($prepRecipe->ingredient) {
                $prepRecipe->ingredient->yield_qty = $prepRecipe->output_qty;
                $prepRecipe->ingredient->yield_unit = $prepRecipe->output_unit;
                $prepRecipe->ingredient->save();
            }

            if (isset($data['items'])) {
                $prepRecipe->items()->delete();
                foreach ($data['items'] as $it) {
                    PrepRecipeItem::create([
                        'prep_recipe_id' => $prepRecipe->id,
                        'ingredient_id'  => $it['ingredient_id'],
                        'qty'            => $it['qty'],
                        'unit'           => $it['unit'],
                        'waste_std'      => $it['waste_std'] ?? 0,
                    ]);
                }
            }

            $prepRecipe->load(['ingredient', 'items.ingredient', 'creator', 'updater']);
            return response()->json($prepRecipe);
        });
    }

    /**
     * Delete sub-recipe
     */
    public function destroyRecipe(PrepRecipe $prepRecipe)
    {
        $prepRecipe->delete();
        return response()->json(['message' => 'Resep olahan berhasil dihapus']);
    }

    // ==========================================
    // BATCH PRODUCTION / PREP COOKING
    // ==========================================

    /**
     * Preview batch cooking session:
     * Calculates required raw materials, verifies current stock on hand at the outlet,
     * checks for shortages, and estimates total batch production cost.
     */
    public function previewBatch(Request $request)
    {
        $request->validate([
            'prep_recipe_id'   => 'required|exists:prep_recipes,id',
            'batch_multiplier' => 'nullable|numeric|min:0.001',
            'outlet_id'        => 'nullable|exists:outlets,id',
        ]);

        $recipe = PrepRecipe::with(['ingredient', 'items.ingredient.outletIngredients'])->findOrFail($request->prep_recipe_id);
        $multiplier = (float)($request->batch_multiplier ?? 1.0);
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = (int)($request->outlet_id ?? $user?->outlet_id ?? 1);
        }

        $itemsPreview = [];
        $totalEstimatedCost = 0.0;
        $hasShortage = false;

        foreach ($recipe->items as $item) {
            $rawIng = $item->ingredient;
            if (!$rawIng) continue;

            $konversi = max((float)$rawIng->konversi, 1);
            $costPerPakai = (float)$rawIng->harga / $konversi;

            // Determine if unit is unit_beli or unit_pakai
            $isUnitBeli = (strtolower($item->unit) === strtolower($rawIng->unit_beli));
            $baseQty = (float)$item->qty;
            $qtyPakai = $isUnitBeli ? ($baseQty * $konversi) : $baseQty;

            // Total needed with waste factor and batch multiplier
            $wasteFactor = 1 + ((float)$item->waste_std / 100);
            $totalNeededPakai = round($qtyPakai * $multiplier * $wasteFactor, 4);

            // Stock in outlet
            $availableStockPakai = $rawIng->stockForOutlet($outletId);
            $shortagePakai = max(0, round($totalNeededPakai - $availableStockPakai, 3));
            $isSufficient = ($availableStockPakai >= $totalNeededPakai);

            if (!$isSufficient) {
                $hasShortage = true;
            }

            $itemCost = round($totalNeededPakai * $costPerPakai, 2);
            $totalEstimatedCost += $itemCost;

            $itemsPreview[] = [
                'ingredient_id'   => $rawIng->id,
                'code'            => $rawIng->code,
                'name'            => $rawIng->name,
                'recipe_qty'      => $baseQty,
                'unit'            => $item->unit,
                'unit_pakai'      => $rawIng->unit_pakai,
                'waste_std'       => (float)$item->waste_std,
                'needed_qty'      => $totalNeededPakai,
                'available_stock' => $availableStockPakai,
                'shortage'        => $shortagePakai,
                'is_sufficient'   => $isSufficient,
                'unit_cost'       => round($costPerPakai, 4),
                'total_cost'      => $itemCost,
            ];
        }

        $expectedOutputQty = round((float)$recipe->output_qty * $multiplier, 3);
        $estimatedUnitCost = $expectedOutputQty > 0 ? round($totalEstimatedCost / $expectedOutputQty, 4) : 0;

        return response()->json([
            'recipe' => [
                'id'          => $recipe->id,
                'name'        => $recipe->name,
                'output_qty'  => (float)$recipe->output_qty,
                'output_unit' => $recipe->output_unit,
                'ingredient'  => [
                    'id'   => $recipe->ingredient?->id,
                    'code' => $recipe->ingredient?->code,
                    'name' => $recipe->ingredient?->name,
                ],
            ],
            'outlet_id'            => $outletId,
            'batch_multiplier'     => $multiplier,
            'expected_output_qty'  => $expectedOutputQty,
            'output_unit'          => $recipe->output_unit,
            'items'                => $itemsPreview,
            'has_shortage'         => $hasShortage,
            'total_estimated_cost' => round($totalEstimatedCost, 2),
            'estimated_unit_cost'  => $estimatedUnitCost,
        ]);
    }

    /**
     * List batch cooking production history
     */
    public function indexBatches(Request $request)
    {
        $query = BatchPrep::with([
            'ingredient',
            'prepRecipe',
            'outlet',
            'user',
            'creator',
            'movements.ingredient',
        ])->orderByDesc('date')->orderByDesc('id');

        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        if ($isOutletBounded) {
            $query->where('outlet_id', (int)$user->outlet_id);
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('ingredient_id')) {
            $query->where('ingredient_id', $request->ingredient_id);
        }

        if ($request->filled('from')) {
            $query->where('date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('date', '<=', $request->to);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function($q) use ($s) {
                $q->where('batch_no', 'like', "%{$s}%")
                  ->orWhereHas('ingredient', function($qi) use ($s) {
                      $qi->where('name', 'like', "%{$s}%")
                         ->orWhere('code', 'like', "%{$s}%");
                  });
            });
        }

        return response()->json($query->paginate(25));
    }

    /**
     * Execute batch cooking session:
     * 1. Deducts raw ingredients via PREP_USAGE StockMovement
     * 2. Adds semi-finished good via PREP_OUTPUT StockMovement
     * 3. Recalculates HPP unit cost of the semi-finished good
     * 4. Logs BatchPrep record
     */
    public function storeBatch(Request $request)
    {
        $data = $request->validate([
            'prep_recipe_id'     => 'required|exists:prep_recipes,id',
            'outlet_id'          => 'required|exists:outlets,id',
            'date'               => 'required|date',
            'batch_multiplier'   => 'required|numeric|min:0.001',
            'actual_output_qty'  => 'required|numeric|min:0.001',
            'notes'              => 'nullable|string|max:500',
            'allow_shortage'     => 'nullable|boolean',
        ]);

        $recipe = PrepRecipe::with(['ingredient', 'items.ingredient'])->findOrFail($data['prep_recipe_id']);
        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = (int)$data['outlet_id'];
        }
        $multiplier = (float)$data['batch_multiplier'];
        $actualOutputQty = (float)$data['actual_output_qty'];
        $date = $data['date'];
        $expectedOutputQty = round((float)$recipe->output_qty * $multiplier, 3);
        $allowShortage = (bool)($data['allow_shortage'] ?? false);

        return DB::transaction(function () use (
            $data, $recipe, $outletId, $multiplier, $actualOutputQty, $date, $expectedOutputQty, $allowShortage, $request
        ) {
            // Check stock availability if not allow_shortage
            $shortages = [];
            foreach ($recipe->items as $item) {
                $rawIng = $item->ingredient;
                $konversi = max((float)$rawIng->konversi, 1);
                $isUnitBeli = (strtolower($item->unit) === strtolower($rawIng->unit_beli));
                $baseQty = (float)$item->qty;
                $qtyPakai = $isUnitBeli ? ($baseQty * $konversi) : $baseQty;
                $wasteFactor = 1 + ((float)$item->waste_std / 100);
                $neededPakai = round($qtyPakai * $multiplier * $wasteFactor, 4);

                $currentStock = $rawIng->stockForOutlet($outletId);
                if ($currentStock < $neededPakai) {
                    $shortages[] = "{$rawIng->name} (Butuh: {$neededPakai} {$rawIng->unit_pakai}, Tersedia: {$currentStock} {$rawIng->unit_pakai})";
                }
            }

            if (!empty($shortages) && !$allowShortage) {
                return response()->json([
                    'message' => 'Stok bahan mentah tidak mencukupi untuk memasak batch ini.',
                    'shortages' => $shortages,
                ], 422);
            }

            // Generate Batch Number: BATCH-YYYYMMDD-XXXX
            $datePrefix = date('Ymd', strtotime($date));
            $latestBatch = BatchPrep::where('batch_no', 'like', "BATCH-{$datePrefix}-%")
                ->orderByDesc('id')
                ->first();

            $nextSeq = 1;
            if ($latestBatch && preg_match("/BATCH-{$datePrefix}-(\d+)/", $latestBatch->batch_no, $matches)) {
                $nextSeq = ((int)$matches[1]) + 1;
            }
            $batchNo = sprintf("BATCH-%s-%04d", $datePrefix, $nextSeq);

            // Create BatchPrep header
            $batchPrep = BatchPrep::create([
                'outlet_id'           => $outletId,
                'batch_no'            => $batchNo,
                'prep_recipe_id'      => $recipe->id,
                'ingredient_id'       => $recipe->ingredient_id,
                'batch_multiplier'    => $multiplier,
                'expected_output_qty' => $expectedOutputQty,
                'actual_output_qty'   => $actualOutputQty,
                'output_unit'         => $recipe->output_unit,
                'total_cost'          => 0, // will update below
                'unit_cost'           => 0, // will update below
                'date'                => $date,
                'notes'               => $data['notes'] ?? null,
                'status'              => 'COMPLETED',
                'user_id'             => $request->user()?->id,
                'created_by'          => $request->user()?->id,
            ]);

            // Deduct Raw Ingredients via PREP_USAGE
            $totalBatchCost = 0.0;
            $semiFinishedIng = $recipe->ingredient;

            foreach ($recipe->items as $item) {
                $rawIng = $item->ingredient;
                $konversi = max((float)$rawIng->konversi, 1);
                $isUnitBeli = (strtolower($item->unit) === strtolower($rawIng->unit_beli));
                $baseQty = (float)$item->qty;
                $qtyPakai = $isUnitBeli ? ($baseQty * $konversi) : $baseQty;
                $wasteFactor = 1 + ((float)$item->waste_std / 100);
                $neededPakai = round($qtyPakai * $multiplier * $wasteFactor, 4);

                $costPerPakai = (float)$rawIng->harga / $konversi;
                $itemTotalCost = round($neededPakai * $costPerPakai, 2);
                $totalBatchCost += $itemTotalCost;

                StockMovement::create([
                    'date'          => $date,
                    'ingredient_id' => $rawIng->id,
                    'outlet_id'     => $outletId,
                    'type'          => 'PREP_USAGE',
                    'qty'           => $neededPakai,
                    'unit_price'    => $costPerPakai,
                    'total_price'   => $itemTotalCost,
                    'cost_before'   => $costPerPakai,
                    'cost_after'    => $costPerPakai,
                    'note'          => "{$batchNo} – Bahan Masak Olahan {$semiFinishedIng->name} ({$actualOutputQty} {$recipe->output_unit})",
                    'batch_prep_id' => $batchPrep->id,
                    'user_id'       => $request->user()?->id,
                    'created_by'    => $request->user()?->id,
                ]);
            }

            // Calculate Unit Cost of produced semi-finished good
            $unitCost = $actualOutputQty > 0 ? round($totalBatchCost / $actualOutputQty, 4) : 0.0;

            // Add produced Semi-Finished Good via PREP_OUTPUT
            $sfKonversi = max((float)$semiFinishedIng->konversi, 1);
            $sfCostPerBeli = round($unitCost * $sfKonversi, 2);

            // Update semi-finished ingredient moving average / valuation
            $semiFinishedIng->recalculateMovingAverage($actualOutputQty, $unitCost, $outletId);

            StockMovement::create([
                'date'          => $date,
                'ingredient_id' => $semiFinishedIng->id,
                'outlet_id'     => $outletId,
                'type'          => 'PREP_OUTPUT',
                'qty'           => $actualOutputQty,
                'unit_price'    => $unitCost,
                'total_price'   => $totalBatchCost,
                'cost_before'   => (float)$semiFinishedIng->harga / $sfKonversi,
                'cost_after'    => $unitCost,
                'note'          => "{$batchNo} – Hasil Masak Batch ({$actualOutputQty} {$recipe->output_unit})",
                'batch_prep_id' => $batchPrep->id,
                'user_id'       => $request->user()?->id,
                'created_by'    => $request->user()?->id,
            ]);

            // Finalize BatchPrep with calculated costs
            $batchPrep->update([
                'total_cost' => round($totalBatchCost, 2),
                'unit_cost'  => $unitCost,
            ]);

            $batchPrep->load([
                'ingredient',
                'prepRecipe',
                'outlet',
                'user',
                'movements.ingredient',
            ]);

            return response()->json($batchPrep, 201);
        });
    }

    /**
     * Show detail of a single batch cooking production session
     */
    public function showBatch(BatchPrep $batchPrep)
    {
        $batchPrep->load([
            'ingredient',
            'prepRecipe.items.ingredient',
            'outlet',
            'user',
            'creator',
            'movements.ingredient',
        ]);

        return response()->json($batchPrep);
    }
}
