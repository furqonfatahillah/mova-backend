<?php

namespace App\Http\Controllers;

use App\Models\BatchPrep;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\OutletIngredient;
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
        $user = $request->user();
        $businessId = $user ? $user->business_id : null;

        // Auto-heal units between prep recipes and semi-finished ingredients
        try {
            self::syncSemiFinishedUnits($businessId);
        } catch (\Throwable $e) {
            // non-blocking
        }

        $query = PrepRecipe::with([
            'ingredient.outletIngredients',
            'ingredient.movements',
            'items.ingredient.outletIngredients',
            'items.ingredient.movements',
            'creator',
            'updater',
        ])->orderBy('name');

        if ($businessId) {
            $query->where(function ($q) use ($businessId) {
                $q->where('business_id', $businessId)
                  ->orWhereHas('ingredient', fn($qi) => $qi->where('business_id', $businessId));
            });
        }

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

        $recipes = $query->get();
        $recipes->each(function ($r) {
            $r->ingredient?->append(['current_stock', 'current_stok_min', 'outlet_stocks', 'has_initial_balance']);
            $r->items?->each(function ($it) {
                $it->ingredient?->append(['current_stock', 'current_stok_min', 'outlet_stocks', 'has_initial_balance']);
            });
        });

        return response()->json($recipes);
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

        $user = $request->user();
        $businessId = $user ? $user->business_id : null;

        // Validasi: Bahan mentah pendukung resep olahan wajib memiliki Saldo Awal
        $itemIngIds = array_column($data['items'], 'ingredient_id');
        $rawIngs = Ingredient::with(['outletIngredients'])->whereIn('id', $itemIngIds)->get()->keyBy('id');
        foreach ($data['items'] as $it) {
            $rawIng = $rawIngs->get($it['ingredient_id']);
            if ($rawIng && !$rawIng->hasInitialBalance()) {
                return response()->json([
                    'message' => "Bahan '{$rawIng->name}' ({$rawIng->code}) belum memiliki Saldo Awal. Silakan masukkan Saldo Awal terlebih dahulu di Master Bahan atau Kartu Stok sebelum membuat/mengubah resep olahan.",
                    'errors'  => [
                        'items' => ["Bahan '{$rawIng->name}' ({$rawIng->code}) belum memiliki Saldo Awal."]
                    ],
                ], 422);
            }
        }

        return DB::transaction(function () use ($data, $request, $businessId) {
            $unitInfo = IngredientController::resolveUnit($data['output_unit'], $businessId, 'gram');
            $canonicalOutputUnit = $unitInfo['symbol'];

            if (!empty($data['ingredient_id'])) {
                $ingredient = Ingredient::findOrFail($data['ingredient_id']);
                $oldUnitPakai = $ingredient->unit_pakai;
                $ingredient->type = 'SEMI_FINISHED';
                $ingredient->yield_qty = $data['output_qty'];
                $ingredient->yield_unit = $canonicalOutputUnit;
                $ingredient->unit_pakai = $canonicalOutputUnit;
                if (!empty($unitInfo['id'])) {
                    $ingredient->unit_pakai_id = $unitInfo['id'];
                }

                if (empty($ingredient->unit_beli) || strtolower((string)$ingredient->unit_beli) === strtolower((string)$oldUnitPakai) || (float)$ingredient->konversi <= 1) {
                    $ingredient->unit_beli = $canonicalOutputUnit;
                    if (!empty($unitInfo['id'])) {
                        $ingredient->unit_beli_id = $unitInfo['id'];
                    }
                    $ingredient->konversi = 1;
                }

                if (isset($data['name'])) $ingredient->name = $data['name'];
                if (isset($data['category'])) $ingredient->category = $data['category'];
                if ($businessId && !$ingredient->business_id) $ingredient->business_id = $businessId;
                $ingredient->save();
            } else {
                // Generate a unique code for the new Semi-Finished ingredient
                $code = $data['code'] ?? null;
                if (!$code) {
                    $lastIng = Ingredient::when($businessId, fn($q) => $q->where('business_id', $businessId))->orderBy('id', 'desc')->first();
                    $nextNum = $lastIng ? ($lastIng->id + 1) : 1;
                    $code = 'PRP-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                    while (Ingredient::when($businessId, fn($q) => $q->where('business_id', $businessId))->where('code', $code)->exists()) {
                        $nextNum++;
                        $code = 'PRP-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                    }
                }

                $ingredient = Ingredient::create([
                    'business_id'   => $businessId,
                    'code'          => $code,
                    'name'          => $data['name'],
                    'category'      => $data['category'] ?? 'Bahan Olahan',
                    'type'          => 'SEMI_FINISHED',
                    'unit_beli'     => $canonicalOutputUnit,
                    'unit_beli_id'  => $unitInfo['id'] ?? null,
                    'unit_pakai'    => $canonicalOutputUnit,
                    'unit_pakai_id' => $unitInfo['id'] ?? null,
                    'konversi'      => 1,
                    'harga'         => 0,
                    'tolerance'     => 0,
                    'yield_qty'     => $data['output_qty'],
                    'yield_unit'    => $canonicalOutputUnit,
                    'active'        => true,
                    'created_by'    => $request->user()?->id,
                ]);
            }

            // Ensure OutletIngredient (Table 3) and IngredientInitialBalance (Table 2) initialized for all outlets
            $outlets = Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))->get();
            if ($outlets->isEmpty()) {
                $outlets = Outlet::all();
            }
            foreach ($outlets as $ot) {
                $oi = OutletIngredient::firstOrCreate(
                    ['outlet_id' => $ot->id, 'ingredient_id' => $ingredient->id],
                    ['current_stock' => 0, 'stok_min' => (float)($data['stok_min'] ?? 0)]
                );
                if (isset($data['stok_min'])) {
                    $oi->stok_min = (float)$data['stok_min'];
                    $oi->save();
                }
                \App\Models\IngredientInitialBalance::firstOrCreate(
                    ['outlet_id' => $ot->id, 'ingredient_id' => $ingredient->id],
                    ['stok_awal' => 0, 'saldo_awal_nominal' => 0, 'business_id' => $businessId]
                );
            }

            // Find existing prep recipe or create new
            $prepRecipe = PrepRecipe::where('ingredient_id', $ingredient->id)->first();
            if (!$prepRecipe) {
                $prepRecipe = new PrepRecipe();
                $prepRecipe->business_id = $businessId;
                $prepRecipe->ingredient_id = $ingredient->id;
                $prepRecipe->created_by = $request->user()?->id;
            } elseif ($businessId && !$prepRecipe->business_id) {
                $prepRecipe->business_id = $businessId;
            }

            $prepRecipe->name = $data['name'];
            $prepRecipe->output_qty = $data['output_qty'];
            $prepRecipe->output_unit = $canonicalOutputUnit;
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
            $businessId = $prepRecipe->business_id ?? $request->user()?->business_id;

            if (isset($data['name'])) $prepRecipe->name = $data['name'];
            if (isset($data['output_qty'])) $prepRecipe->output_qty = $data['output_qty'];
            $unitInfo = null;
            if (isset($data['output_unit'])) {
                $unitInfo = IngredientController::resolveUnit($data['output_unit'], $businessId, 'gram');
                $prepRecipe->output_unit = $unitInfo['symbol'];
            }
            if (array_key_exists('notes', $data)) $prepRecipe->notes = $data['notes'];
            $prepRecipe->updated_by = $request->user()?->id;
            $prepRecipe->save();

            // Update associated ingredient yield info and usage units
            if ($prepRecipe->ingredient) {
                $ingredient = $prepRecipe->ingredient;
                $oldUnitPakai = $ingredient->unit_pakai;

                $ingredient->type = 'SEMI_FINISHED';
                $ingredient->yield_qty = $prepRecipe->output_qty;
                $ingredient->yield_unit = $prepRecipe->output_unit;
                $ingredient->unit_pakai = $prepRecipe->output_unit;

                if ($unitInfo && !empty($unitInfo['id'])) {
                    $ingredient->unit_pakai_id = $unitInfo['id'];
                }

                if (empty($ingredient->unit_beli) || strtolower((string)$ingredient->unit_beli) === strtolower((string)$oldUnitPakai) || (float)$ingredient->konversi <= 1) {
                    $ingredient->unit_beli = $prepRecipe->output_unit;
                    if ($unitInfo && !empty($unitInfo['id'])) {
                        $ingredient->unit_beli_id = $unitInfo['id'];
                    }
                    $ingredient->konversi = 1;
                }

                if (isset($data['name'])) {
                    $ingredient->name = $data['name'];
                }
                $ingredient->save();
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
        $reasons = [];
        if (\App\Models\BatchPrep::where('prep_recipe_id', $prepRecipe->id)->exists()) {
            $reasons[] = 'riwayat produksi batch prep';
        }
        if (\App\Models\RecipeItem::where('ingredient_id', $prepRecipe->ingredient_id)->exists()) {
            $reasons[] = 'resep Master Menu';
        }
        if (\App\Models\StockMovement::where('ingredient_id', $prepRecipe->ingredient_id)->exists()) {
            $reasons[] = 'mutasi kartu stok bahan olahan';
        }

        if (!empty($reasons)) {
            $reasonStr = implode(', ', $reasons);
            return response()->json([
                'message' => "Dilarang menghapus resep olahan '{$prepRecipe->name}'! Resep ini sudah digunakan dalam {$reasonStr}. Menghapus resep ini akan merusak integritas riwayat produksi dan kartu stok.",
                'error'   => 'PREP_RECIPE_LOCKED_BY_USAGE',
            ], 422);
        }

        $prepRecipe->items()->delete();
        $prepRecipe->delete();
        return response()->json(['message' => 'Resep olahan berhasil dihapus']);
    }

    /**
     * Synchronize and heal unit consistency between prep recipes and semi-finished ingredients.
     * Ensures output_unit == unit_pakai == yield_unit == unit_beli (when konversi <= 1).
     */
    public static function syncSemiFinishedUnits($businessId = null)
    {
        $recipes = PrepRecipe::with('ingredient')
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->get();

        foreach ($recipes as $recipe) {
            $ingredient = $recipe->ingredient;
            if (!$ingredient) continue;

            $changed = false;
            if ($ingredient->type !== 'SEMI_FINISHED') {
                $ingredient->type = 'SEMI_FINISHED';
                $changed = true;
            }

            $outputUnit = trim($recipe->output_unit ?? '');
            if (!empty($outputUnit)) {
                $unitPakaiInfo = IngredientController::resolveUnit($outputUnit, $ingredient->business_id, 'gram');
                $canonicalUnit = $unitPakaiInfo['symbol'];

                if ($recipe->output_unit !== $canonicalUnit) {
                    $recipe->output_unit = $canonicalUnit;
                    $recipe->saveQuietly();
                }

                if (strtolower((string)$ingredient->unit_pakai) !== strtolower($canonicalUnit)) {
                    $oldPakai = $ingredient->unit_pakai;
                    $ingredient->unit_pakai = $canonicalUnit;
                    $ingredient->unit_pakai_id = $unitPakaiInfo['id'] ?? null;
                    $ingredient->yield_unit = $canonicalUnit;
                    if (empty($ingredient->unit_beli) || strtolower((string)$ingredient->unit_beli) === strtolower((string)$oldPakai) || (float)$ingredient->konversi <= 1) {
                        $ingredient->unit_beli = $canonicalUnit;
                        $ingredient->unit_beli_id = $unitPakaiInfo['id'] ?? null;
                        $ingredient->konversi = 1;
                    }
                    $changed = true;
                } elseif ($ingredient->yield_unit !== $canonicalUnit) {
                    $ingredient->yield_unit = $canonicalUnit;
                    $changed = true;
                }
            }

            if (!empty($recipe->output_qty) && (float)$ingredient->yield_qty !== (float)$recipe->output_qty) {
                $ingredient->yield_qty = $recipe->output_qty;
                $changed = true;
            }

            if ($changed) {
                $ingredient->saveQuietly();
            }
        }

        // Also heal any orphan or direct SEMI_FINISHED ingredients where unit_pakai != yield_unit
        $semiIngredients = Ingredient::where('type', 'SEMI_FINISHED')
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->get();
        foreach ($semiIngredients as $sf) {
            $u = $sf->yield_unit ?: $sf->unit_pakai;
            if ($u) {
                $uInfo = IngredientController::resolveUnit($u, $sf->business_id, 'gram');
                $sym = $uInfo['symbol'];
                $c = false;
                if ($sf->unit_pakai !== $sym) {
                    $sf->unit_pakai = $sym;
                    $sf->unit_pakai_id = $uInfo['id'] ?? null;
                    $c = true;
                }
                if ($sf->yield_unit !== $sym) {
                    $sf->yield_unit = $sym;
                    $c = true;
                }
                if ((float)$sf->konversi <= 1 && $sf->unit_beli !== $sym) {
                    $sf->unit_beli = $sym;
                    $sf->unit_beli_id = $uInfo['id'] ?? null;
                    $sf->konversi = 1;
                    $c = true;
                }
                if ($c) {
                    $sf->saveQuietly();
                }
            }
        }

        // Auto-heal OutletIngredient rows and isolated current_stock for all semi-finished goods
        $allOutlets = Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))->get();
        if ($allOutlets->isEmpty()) {
            $allOutlets = Outlet::all();
        }

        foreach ($semiIngredients as $sf) {
            $totalConsolidated = 0.0;
            foreach ($allOutlets as $ot) {
                $oi = OutletIngredient::firstOrCreate(
                    ['outlet_id' => $ot->id, 'ingredient_id' => $sf->id],
                    ['current_stock' => 0]
                );

                if ($oi->current_stock === null) {
                    $movNet = (float)StockMovement::where('ingredient_id', $sf->id)
                        ->where('outlet_id', $ot->id)
                        ->where('type', '!=', 'INITIAL')
                        ->selectRaw("SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                        ->value('net_qty') ?? 0.0;
                    $initStock = (float)($oi->initialBalance?->stok_awal ?? 0);
                    $oi->current_stock = round($initStock + $movNet, 3);
                    $oi->saveQuietly();
                }
                $totalConsolidated += (float)$oi->current_stock;
            }

            // Sync consolidated stock at ingredient level
            if (isset($sf->attributes['current_stock'])) {
                $sf->current_stock = round($totalConsolidated, 3);
                $sf->saveQuietly();
            }
        }
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
        $businessId = $user ? $user->business_id : null;

        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = (int)($request->outlet_id ?? $user?->outlet_id ?? Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))->value('id'));
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

        $semiFinishedIng = $recipe->ingredient;
        $sfStock = $semiFinishedIng ? $semiFinishedIng->stockForOutlet($outletId) : 0.0;
        $negativeItems = [];
        if ($sfStock < -0.0001) {
            $negativeItems[] = "Bahan Olahan '{$semiFinishedIng->name}' (Stok: " . round($sfStock, 3) . " {$semiFinishedIng->unit_pakai})";
        }
        foreach ($recipe->items as $item) {
            $rawIng = $item->ingredient;
            if ($rawIng) {
                $rawStock = $rawIng->stockForOutlet($outletId);
                if ($rawStock < -0.0001) {
                    $negativeItems[] = "Bahan Mentah '{$rawIng->name}' (Stok: " . round($rawStock, 3) . " {$rawIng->unit_pakai})";
                }
            }
        }
        $hasNegativeStock = !empty($negativeItems);

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
            'has_negative_stock'   => $hasNegativeStock,
            'negative_items'       => $negativeItems,
            'output_stock'         => $sfStock,
            'total_estimated_cost' => round($totalEstimatedCost, 2),
            'estimated_unit_cost'  => $estimatedUnitCost,
        ]);
    }

    /**
     * List batch cooking production history
     */
    public function indexBatches(Request $request)
    {
        $user = $request->user();
        $businessId = $user ? $user->business_id : null;

        $query = BatchPrep::with([
            'ingredient',
            'prepRecipe',
            'outlet',
            'user',
            'creator',
            'movements.ingredient',
        ])->orderByDesc('date')->orderByDesc('id');

        if ($businessId) {
            $query->where(function ($q) use ($businessId) {
                $q->where('business_id', $businessId)
                  ->orWhereHas('outlet', fn($qo) => $qo->where('business_id', $businessId));
            });
        }

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
        $businessId = $user ? $user->business_id : null;

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
            $data, $recipe, $outletId, $multiplier, $actualOutputQty, $date, $expectedOutputQty, $allowShortage, $request, $businessId
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
            $latestBatch = BatchPrep::when($businessId, fn($q) => $q->where('business_id', $businessId))
                ->where('batch_no', 'like', "BATCH-{$datePrefix}-%")
                ->orderByDesc('id')
                ->first();

            $nextSeq = 1;
            if ($latestBatch && preg_match("/BATCH-{$datePrefix}-(\d+)/", $latestBatch->batch_no, $matches)) {
                $nextSeq = ((int)$matches[1]) + 1;
            }
            $batchNo = sprintf("BATCH-%s-%04d", $datePrefix, $nextSeq);

            // Create BatchPrep header
            $batchPrep = BatchPrep::create([
                'business_id'         => $businessId,
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
                    'business_id'   => $businessId,
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

            // Calculate variance between actual and expected yield
            $varianceQty = round($actualOutputQty - $expectedOutputQty, 3);
            $variancePct = $expectedOutputQty > 0 ? round(($varianceQty / $expectedOutputQty) * 100, 1) : 0;
            $varianceSuffix = "";
            if (abs($varianceQty) > 0.001) {
                $sign = $varianceQty > 0 ? "+{$varianceQty}" : "{$varianceQty}";
                $pctSign = $variancePct > 0 ? "+{$variancePct}%" : "{$variancePct}%";
                $varianceSuffix = " [Temuan Deviasi Hasil: {$sign} {$recipe->output_unit} ({$pctSign}) vs Target {$expectedOutputQty} {$recipe->output_unit}]";
            }

            StockMovement::create([
                'business_id'   => $businessId,
                'date'          => $date,
                'ingredient_id' => $semiFinishedIng->id,
                'outlet_id'     => $outletId,
                'type'          => 'PREP_OUTPUT',
                'qty'           => $actualOutputQty,
                'unit_price'    => $unitCost,
                'total_price'   => $totalBatchCost,
                'cost_before'   => (float)$semiFinishedIng->harga / $sfKonversi,
                'cost_after'    => $unitCost,
                'note'          => "{$batchNo} – Hasil Masak Batch ({$actualOutputQty} {$recipe->output_unit}){$varianceSuffix}",
                'batch_prep_id' => $batchPrep->id,
                'user_id'       => $request->user()?->id,
                'created_by'    => $request->user()?->id,
            ]);

            // Hitung ulang moving average dan biaya pokok olahan
            $semiFinishedIng->recomputeMovingAverageFromHistory($outletId);

            // Finalize BatchPrep with calculated costs
            $batchPrep->update([
                'total_cost' => round($totalBatchCost, 2),
                'unit_cost'  => $unitCost,
            ]);

            // Catat jurnal akuntansi untuk batch prep (Persediaan Olahan pada Persediaan Bahan Baku)
            try {
                \App\Services\AccountingService::postBatchPrep($batchPrep);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Gagal mencatat jurnal batch prep: " . $e->getMessage());
            }

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

    public function destroyBatch(BatchPrep $batchPrep)
    {
        return DB::transaction(function () use ($batchPrep) {
            $batchNo = $batchPrep->batch_no;
            // Delete journal entries associated with this batch prep or its movements
            $movIds = StockMovement::where('batch_prep_id', $batchPrep->id)->pluck('id');
            if ($movIds->isNotEmpty()) {
                \App\Models\JournalEntry::where('reference_type', 'STOCK_MOVEMENT')->whereIn('reference_id', $movIds)->delete();
            }
            \App\Models\JournalEntry::where('reference_type', 'BATCH_PREP')->where('reference_id', $batchPrep->id)->delete();

            // Delete all stock movements associated with this batch
            StockMovement::where('batch_prep_id', $batchPrep->id)->delete();
            $batchPrep->delete();

            return response()->json([
                'message' => "Produksi {$batchNo} berhasil dibatalkan dan mutasi stok telah dikembalikan normal.",
            ]);
        });
    }
}
