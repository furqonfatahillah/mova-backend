<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\PrepRecipe;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $user ? $user->business_id : null;

        // Auto-heal units between prep recipes and semi-finished ingredients
        try {
            BatchPrepController::syncSemiFinishedUnits($businessId);
        } catch (\Throwable $e) {
            // non-blocking
        }

        $query = Ingredient::with(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'categoryModel'])
            ->orderBy('code');

        if ($request->filled('type') && in_array($request->type, ['RAW', 'SEMI_FINISHED'])) {
            $query->where('type', $request->type);
        }

        if ($request->filled('category')) {
            $query->where(function ($q) use ($request) {
                $q->where('category', $request->category)
                  ->orWhereHas('categoryModel', fn($cq) => $cq->where('name', $request->category));
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $ingredients = $query->get();

        $targetOutletId = null;
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $targetOutletId = (int)$user->outlet_id;
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $targetOutletId = (int)$request->outlet_id;
        }

        $ingIds = $ingredients->pluck('id')->all();

        // ⚡ ULTRA PERFORMANCE: Compute movement aggregates for ALL ingredients in 1 single fast SQL query!
        // Avoids loading tens of thousands of heavy Eloquent models into PHP memory.
        if (!empty($ingIds)) {
            $movSummary = DB::table('stock_movements')
                ->whereIn('ingredient_id', $ingIds)
                ->where('type', '!=', 'INITIAL')
                ->selectRaw("ingredient_id, outlet_id, SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                ->groupBy('ingredient_id', 'outlet_id')
                ->get();

            $movMap = [];
            foreach ($movSummary as $row) {
                $movMap[$row->ingredient_id][$row->outlet_id] = (float) $row->net_qty;
            }

            foreach ($ingredients as $ing) {
                $ing->setPrecomputedMovements($movMap[$ing->id] ?? []);
            }
        }

        // ⚡ Explicitly append stock and outlet-isolated pricing attributes
        $ingredients->each(function ($ing) {
            $ing->append(['current_stock', 'current_stok_min', 'current_harga', 'outlet_stocks', 'has_initial_balance', 'is_semi_finished', 'last_purchase_price']);
            if ($ing->current_harga > 0) {
                $ing->harga = $ing->current_harga;
            }
        });

        return response()->json($ingredients);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $businessId = $user?->business_id;
        if ($user?->isSuperadminPlatform()) {
            $businessId = $request->header('X-Business-Id') ?? $request->query('business_id') ?? $request->input('business_id');
        }

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('ingredients', 'code')->where(function ($query) use ($businessId) {
                    return $businessId ? $query->where('business_id', $businessId) : $query;
                }),
            ],
            'name'        => 'required|string|max:255',
            'category'    => 'nullable|string|max:100',
            'category_id' => 'nullable|integer',
            'type'        => 'nullable|string|in:RAW,SEMI_FINISHED',
            'unit_beli'   => 'required|string|max:20',
            'unit_pakai'  => 'required|string|max:20',
            'konversi'    => 'required|numeric|min:0.001',
            'harga'       => 'required|numeric|min:0',
            'stok_awal'   => 'nullable|numeric|min:0',
            'stok_min'    => 'nullable|numeric|min:0',
            'tolerance'   => 'nullable|numeric|min:0|max:100',
            'yield_qty'   => 'nullable|numeric|min:0',
            'yield_unit'  => 'nullable|string|max:20',
            'outlet_id'   => 'nullable',
            'active'      => 'nullable|boolean',
        ]);

        if (!empty($data['category_id'])) {
            $cat = Category::find($data['category_id']);
            if ($cat) {
                $data['category'] = $cat->name;
            }
        } elseif (!empty($data['category'])) {
            $cat = Category::firstOrCreate(
                ['business_id' => $businessId, 'name' => trim($data['category']), 'type' => 'INGREDIENT'],
                ['slug' => Str::slug($data['category']), 'color' => '#00B14F', 'icon' => 'Package']
            );
            $data['category_id'] = $cat->id;
        } else {
            $data['category'] = 'Perlengkapan';
        }

        $data['type'] = $data['type'] ?? 'RAW';
        $data['created_by'] = $request->user()?->id;

        if (!empty($data['unit_beli'])) {
            $unitBeliInfo = $this->resolveUnit($data['unit_beli'], $businessId, 'kg');
            $data['unit_beli'] = $unitBeliInfo['symbol'];
            $data['unit_beli_id'] = $unitBeliInfo['id'];
        }
        if (!empty($data['unit_pakai'])) {
            $unitPakaiInfo = $this->resolveUnit($data['unit_pakai'], $businessId, 'gram');
            $data['unit_pakai'] = $unitPakaiInfo['symbol'];
            $data['unit_pakai_id'] = $unitPakaiInfo['id'];
        }

        $targetOutletId = $request->input('outlet_id');
        $data['last_purchase_price'] = $data['harga'] ?? null;
        $ingredient = Ingredient::create($data);

        // Sync to OutletIngredient for all business outlets
        $businessOutlets = \App\Models\Outlet::where('business_id', $businessId)->get();
        if ($businessOutlets->isEmpty()) {
            $businessOutlets = \App\Models\Outlet::all();
        }

        $outletStocksInput = $request->input('outlet_stocks');
        if (is_array($outletStocksInput) && !empty($outletStocksInput)) {
            $stocksByOutlet = collect($outletStocksInput)->keyBy('outlet_id');
            foreach ($businessOutlets as $bo) {
                $row = $stocksByOutlet->get($bo->id);
                $stokAwal = $row && isset($row['stok_awal']) ? (float)$row['stok_awal'] : 0.0;
                $stokMin = $row && isset($row['stok_min']) ? (float)$row['stok_min'] : (float)($data['stok_min'] ?? 0);
                $nominal = $row && isset($row['saldo_awal_nominal']) ? (float)$row['saldo_awal_nominal'] : ($stokAwal > 0 ? round($stokAwal * ($ingredient->harga / max((float)$ingredient->konversi, 1)), 2) : 0.0);
                $tanggal = $row['tanggal_saldo_awal'] ?? $data['tanggal_saldo_awal'] ?? now()->toDateString();

                \App\Models\OutletIngredient::updateOrCreate(
                    [
                        'outlet_id'     => $bo->id,
                        'ingredient_id' => $ingredient->id,
                    ],
                    [
                        'current_stock'       => $stokAwal,
                        'stok_min'            => $stokMin,
                        'harga'               => (float)($data['harga'] ?? 0),
                        'last_purchase_price' => (float)($data['harga'] ?? 0),
                    ]
                );

                // Table 2: Dedicated Saldo Awal
                \App\Models\IngredientInitialBalance::updateOrCreate(
                    [
                        'outlet_id'     => $bo->id,
                        'ingredient_id' => $ingredient->id,
                    ],
                    [
                        'business_id'        => $businessId,
                        'stok_awal'          => $stokAwal,
                        'saldo_awal_nominal' => $nominal,
                        'tanggal_saldo_awal' => $tanggal,
                        'created_by'         => $user?->id,
                        'updated_by'         => $user?->id,
                    ]
                );
            }
        } else {
            foreach ($businessOutlets as $bo) {
                $isTarget = false;
                if ($targetOutletId === 'ALL' || $targetOutletId === 'all') {
                    $isTarget = ($bo->is_main || (int)$bo->id === 1);
                } elseif (!empty($targetOutletId)) {
                    $isTarget = ((int)$bo->id === (int)$targetOutletId);
                } else {
                    $isTarget = ($bo->is_main || (int)$bo->id === 1 || $user?->outlet_id === $bo->id);
                }

                $stokAwal = $isTarget ? (float)($data['stok_awal'] ?? 0) : 0.0;
                $nominal = $stokAwal > 0 ? round($stokAwal * ($ingredient->harga / max((float)$ingredient->konversi, 1)), 2) : 0.0;
                $tanggal = $data['tanggal_saldo_awal'] ?? now()->toDateString();

                \App\Models\OutletIngredient::updateOrCreate(
                    [
                        'outlet_id'     => $bo->id,
                        'ingredient_id' => $ingredient->id,
                    ],
                    [
                        'current_stock'       => $stokAwal,
                        'stok_min'            => (float)($data['stok_min'] ?? 0),
                        'harga'               => (float)($data['harga'] ?? 0),
                        'last_purchase_price' => (float)($data['harga'] ?? 0),
                    ]
                );

                // Table 2: Dedicated Saldo Awal
                \App\Models\IngredientInitialBalance::updateOrCreate(
                    [
                        'outlet_id'     => $bo->id,
                        'ingredient_id' => $ingredient->id,
                    ],
                    [
                        'business_id'        => $businessId,
                        'stok_awal'          => $stokAwal,
                        'saldo_awal_nominal' => $nominal,
                        'tanggal_saldo_awal' => $tanggal,
                        'created_by'         => $user?->id,
                        'updated_by'         => $user?->id,
                    ]
                );
            }
        }



        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements', 'categoryModel']);
        $ingredient->append(['current_stock', 'current_stok_min', 'current_harga', 'outlet_stocks', 'has_initial_balance', 'is_semi_finished', 'last_purchase_price']);
        return response()->json($ingredient, 201);
    }

    public function show(Ingredient $ingredient)
    {
        $ingredient->refresh();
        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements', 'categoryModel']);
        $ingredient->append(['current_stock', 'current_stok_min', 'current_harga', 'outlet_stocks', 'has_initial_balance', 'is_semi_finished', 'last_purchase_price']);
        if ($ingredient->current_harga > 0) {
            $ingredient->harga = $ingredient->current_harga;
        }
        return response()->json($ingredient);
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $businessId = $ingredient->business_id ?? $request->user()?->business_id;

        $data = $request->validate([
            'code' => [
                'sometimes', 'string', 'max:20',
                Rule::unique('ingredients', 'code')->where(function ($query) use ($businessId) {
                    return $businessId ? $query->where('business_id', $businessId) : $query;
                })->ignore($ingredient->id),
            ],
            'name'        => 'sometimes|string|max:255',
            'category'    => 'sometimes|string|max:100',
            'category_id' => 'nullable|integer',
            'type'        => 'nullable|string|in:RAW,SEMI_FINISHED',
            'unit_beli'   => 'sometimes|string|max:20',
            'unit_pakai'  => 'sometimes|string|max:20',
            'konversi'    => 'sometimes|numeric|min:0.001',
            'harga'       => 'sometimes|numeric|min:0',
            'stok_awal'   => 'nullable|numeric|min:0',
            'stok_min'    => 'nullable|numeric|min:0',
            'tolerance'   => 'nullable|numeric|min:0|max:100',
            'yield_qty'   => 'nullable|numeric|min:0',
            'yield_unit'  => 'nullable|string|max:20',
            'active'      => 'nullable|boolean',
        ]);

        if (array_key_exists('category_id', $data) && !empty($data['category_id'])) {
            $cat = Category::find($data['category_id']);
            if ($cat) {
                $data['category'] = $cat->name;
            }
        } elseif (!empty($data['category'])) {
            $cat = Category::firstOrCreate(
                ['business_id' => $businessId, 'name' => trim($data['category']), 'type' => 'INGREDIENT'],
                ['slug' => Str::slug($data['category']), 'color' => '#00B14F', 'icon' => 'Package']
            );
            $data['category_id'] = $cat->id;
        }

        if (array_key_exists('unit_beli', $data) && !empty($data['unit_beli'])) {
            $unitBeliInfo = $this->resolveUnit($data['unit_beli'], $businessId, 'kg');
            $data['unit_beli'] = $unitBeliInfo['symbol'];
            $data['unit_beli_id'] = $unitBeliInfo['id'];
        }
        if (array_key_exists('unit_pakai', $data) && !empty($data['unit_pakai'])) {
            $unitPakaiInfo = $this->resolveUnit($data['unit_pakai'], $businessId, 'gram');
            $data['unit_pakai'] = $unitPakaiInfo['symbol'];
            $data['unit_pakai_id'] = $unitPakaiInfo['id'];
        }

        $data['updated_by'] = $request->user()?->id;

        $ingredient->update($data);

        // SYNC with associated PrepRecipe if this is a SEMI_FINISHED ingredient or has a prep recipe
        if ($ingredient->type === 'SEMI_FINISHED' || $ingredient->prepRecipe) {
            $prepRecipe = $ingredient->prepRecipe ?: PrepRecipe::where('ingredient_id', $ingredient->id)->first();
            if ($prepRecipe) {
                $needsPrepSave = false;
                if (!empty($ingredient->unit_pakai) && $prepRecipe->output_unit !== $ingredient->unit_pakai) {
                    $prepRecipe->output_unit = $ingredient->unit_pakai;
                    $needsPrepSave = true;
                }
                if (!empty($ingredient->yield_qty) && (float)$prepRecipe->output_qty !== (float)$ingredient->yield_qty) {
                    $prepRecipe->output_qty = $ingredient->yield_qty;
                    $needsPrepSave = true;
                }
                if (!empty($ingredient->name) && $prepRecipe->name !== $ingredient->name) {
                    $prepRecipe->name = $ingredient->name;
                    $needsPrepSave = true;
                }
                if ($needsPrepSave) {
                    $prepRecipe->saveQuietly();
                }

                // Also make sure yield_unit is synced to unit_pakai
                if ($ingredient->yield_unit !== $ingredient->unit_pakai) {
                    $ingredient->yield_unit = $ingredient->unit_pakai;
                    $ingredient->saveQuietly();
                }
            }
        }

        if ($request->has('outlet_stocks') && is_array($request->input('outlet_stocks'))) {
            $this->applyOutletStocks($ingredient, $request->input('outlet_stocks'));
        } elseif (array_key_exists('harga', $data) && (float)$data['harga'] > 0) {
            $newHarga = (float)$data['harga'];
            \App\Models\OutletIngredient::where('ingredient_id', $ingredient->id)
                ->where(function($q) {
                    $q->whereNull('harga')->orWhere('harga', '<=', 0);
                })
                ->update(['harga' => $newHarga]);

            $konv = max((float)$ingredient->konversi, 1);
            $initialBalances = \App\Models\IngredientInitialBalance::where('ingredient_id', $ingredient->id)
                ->where(function($q) {
                    $q->whereNull('saldo_awal_nominal')->orWhere('saldo_awal_nominal', '<=', 0);
                })
                ->where('stok_awal', '>', 0)
                ->get();
            foreach ($initialBalances as $ib) {
                $ib->saldo_awal_nominal = round($ib->stok_awal * ($newHarga / $konv), 2);
                $ib->save();
            }
        }

        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements', 'categoryModel']);
        $ingredient->append(['current_stock', 'current_stok_min', 'current_harga', 'outlet_stocks', 'has_initial_balance', 'is_semi_finished', 'last_purchase_price']);
        return response()->json($ingredient);
    }

    public function updateOutletBalances(Request $request, Ingredient $ingredient)
    {
        $request->validate([
            'outlet_stocks' => 'required|array',
            'outlet_stocks.*.outlet_id' => 'required|integer',
            'outlet_stocks.*.stok_awal' => 'nullable|numeric|min:0',
            'outlet_stocks.*.saldo_awal_nominal' => 'nullable|numeric|min:0',
            'outlet_stocks.*.harga' => 'nullable|numeric|min:0',
            'outlet_stocks.*.tanggal_saldo_awal' => 'nullable|date',
            'outlet_stocks.*.stok_min' => 'nullable|numeric|min:0',
        ]);

        $this->applyOutletStocks($ingredient, $request->input('outlet_stocks'));

        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements', 'categoryModel']);
        $ingredient->append(['current_stock', 'current_stok_min', 'current_harga', 'outlet_stocks', 'has_initial_balance', 'is_semi_finished', 'last_purchase_price']);

        return response()->json([
            'message' => 'Saldo awal dan batas minimum cabang berhasil diperbarui',
            'ingredient' => $ingredient
        ]);
    }

    private function applyOutletStocks(Ingredient $ingredient, array $outletStocks): void
    {
        $konversi = max((float)$ingredient->konversi, 1);
        $harga = (float)$ingredient->harga;

        foreach ($outletStocks as $row) {
            $outletId = (int)$row['outlet_id'];
            $stokAwal = (float)($row['stok_awal'] ?? 0);
            $stokMin = array_key_exists('stok_min', $row) && $row['stok_min'] !== null ? (float)$row['stok_min'] : null;

            $outletRow = \App\Models\OutletIngredient::firstOrNew([
                'outlet_id'     => $outletId,
                'ingredient_id' => $ingredient->id,
            ]);

            // Resolusi harga cabang: ambil dari input $row['harga'], atau harga outlet saat ini, atau master harga
            $inputHarga = array_key_exists('harga', $row) && $row['harga'] !== null && (float)$row['harga'] > 0
                ? (float)$row['harga']
                : null;

            if ($inputHarga !== null) {
                $outletRow->harga = $inputHarga;
                // Jika master catalog harga masih 0, perbarui master catalog juga
                if ((float)$ingredient->harga <= 0) {
                    $ingredient->harga = $inputHarga;
                    $ingredient->saveQuietly();
                    $harga = $inputHarga;
                }
            } elseif (!$outletRow->harga || (float)$outletRow->harga <= 0) {
                $outletRow->harga = $harga;
            }

            $effHarga = (float)($outletRow->harga ?? $harga);

            $nominal = array_key_exists('saldo_awal_nominal', $row) && $row['saldo_awal_nominal'] !== null && (float)$row['saldo_awal_nominal'] > 0
                ? (float)$row['saldo_awal_nominal']
                : ($stokAwal > 0 ? round($stokAwal * ($effHarga / $konversi), 2) : 0.0);
            $tanggal = $row['tanggal_saldo_awal'] ?? $ingredient->tanggal_saldo_awal ?? now()->toDateString();

            // Calculate net movements for this outlet
            $netMov = (float) \Illuminate\Support\Facades\DB::table('stock_movements')
                ->where('ingredient_id', $ingredient->id)
                ->where('outlet_id', $outletId)
                ->where('type', '!=', 'INITIAL')
                ->selectRaw("SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                ->value('net_qty') ?? 0.0;

            $currentStock = round($stokAwal + $netMov, 3);

            if ($stokMin !== null) {
                $outletRow->stok_min = $stokMin;
            }
            $outletRow->current_stock = $currentStock;
            $outletRow->save();

            // Table 2: Dedicated Saldo Awal
            \App\Models\IngredientInitialBalance::updateOrCreate(
                [
                    'outlet_id'     => $outletId,
                    'ingredient_id' => $ingredient->id,
                ],
                [
                    'business_id'        => $ingredient->business_id,
                    'stok_awal'          => $stokAwal,
                    'saldo_awal_nominal' => $nominal,
                    'tanggal_saldo_awal' => $tanggal,
                    'updated_by'         => auth()->id(),
                ]
            );
        }
    }

    private function cleanupIngredientRelations(array $validIds): void
    {
        if (empty($validIds)) {
            return;
        }

        // 1. Tables to nullify references
        $tablesToNullify = [
            'payables'              => 'ingredient_id',
            'modifier_options'      => 'ingredient_id',
            'transaction_modifiers' => 'ingredient_id',
            'urgent_notes'          => 'ingredient_id',
            'menu_hpp_histories'    => 'ingredient_id',
        ];

        foreach ($tablesToNullify as $table => $column) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                \Illuminate\Support\Facades\DB::table($table)->whereIn($column, $validIds)->update([$column => null]);
            }
        }

        // 2. Tables to delete child/dependent rows
        $tablesToDelete = [
            'recipe_items',
            'prep_recipe_items',
            'batch_preps',
            'prep_recipes',
            'bundle_items',
            'waste_logs',
            'stock_movements',
            'opnames',
            'outlet_ingredients',
            'transfer_items',
        ];

        foreach ($tablesToDelete as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table) && \Illuminate\Support\Facades\Schema::hasColumn($table, 'ingredient_id')) {
                \Illuminate\Support\Facades\DB::table($table)->whereIn('ingredient_id', $validIds)->delete();
            }
        }
    }

    /**
     * Cek apakah bahan / perlengkapan dilarang dihapus karena sedang digunakan
     * dalam Resep Master Menu, sub-resep olahan, atau sudah memiliki riwayat transaksi/mutasi stok.
     */
    protected function getDeletionBlockReason(Ingredient $ingredient): ?string
    {
        $reasons = [];

        // 1. Cek penggunaan dalam Resep Master Menu (Gramasi / BOM)
        $menuIds = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('recipe_items') && \Illuminate\Support\Facades\Schema::hasTable('recipes')) {
            $menuIds = \Illuminate\Support\Facades\DB::table('recipe_items')
                ->join('recipes', 'recipe_items.recipe_id', '=', 'recipes.id')
                ->where('recipe_items.ingredient_id', $ingredient->id)
                ->pluck('recipes.menu_id')
                ->all();
        }

        // 2. Cek jika bahan digunakan dalam sub-resep (bahan setengah jadi / olahan)
        if (\Illuminate\Support\Facades\Schema::hasTable('prep_recipe_items')) {
            $isUsedInPrep = \Illuminate\Support\Facades\DB::table('prep_recipe_items')
                ->where('ingredient_id', $ingredient->id)
                ->exists();
            if ($isUsedInPrep) {
                $reasons[] = 'resep bahan olahan (Batch Prep / Sub-Recipe)';
            }
        }

        // 3. Cek jika bahan merupakan output dari resep olahan itu sendiri
        if (\Illuminate\Support\Facades\Schema::hasTable('prep_recipes')) {
            $isPrepOutput = \Illuminate\Support\Facades\DB::table('prep_recipes')
                ->where('ingredient_id', $ingredient->id)
                ->exists();
            if ($isPrepOutput) {
                $reasons[] = 'resep produksi olahan (Prep Recipe Output)';
            }
        }

        // 4. Cek jika bahan/perlengkapan dibundel langsung dalam menu paket (bundle)
        if (\Illuminate\Support\Facades\Schema::hasTable('bundle_items') && \Illuminate\Support\Facades\Schema::hasColumn('bundle_items', 'ingredient_id')) {
            $bundleMenus = \Illuminate\Support\Facades\DB::table('bundle_items')
                ->where('ingredient_id', $ingredient->id)
                ->pluck('menu_id')
                ->all();
            $menuIds = array_merge($menuIds, $bundleMenus);
        }

        $allMenuIds = array_values(array_unique(array_filter($menuIds)));
        if (!empty($allMenuIds)) {
            $menus = \Illuminate\Support\Facades\DB::table('menus')->whereIn('id', $allMenuIds)->pluck('name')->all();
            $menuList = implode(', ', array_slice($menus, 0, 3));
            if (count($menus) > 3) {
                $menuList .= ' (+' . (count($menus) - 3) . ' menu lainnya)';
            }
            $reasons[] = "resep/gramasi Master Menu [{$menuList}]";

            // Cek apakah menu-menu tersebut sudah memiliki transaksi penjualan di POS
            if (\Illuminate\Support\Facades\Schema::hasTable('transactions') && \Illuminate\Support\Facades\Schema::hasColumn('transactions', 'menu_id')) {
                $hasMenuSales = \Illuminate\Support\Facades\DB::table('transactions')
                    ->whereIn('menu_id', $allMenuIds)
                    ->exists();
                if ($hasMenuSales) {
                    $reasons[] = 'penjualan menu di kasir/POS';
                }
            }
        }

        // 5. Cek riwayat mutasi kartu stok (stock_movements)
        if (\Illuminate\Support\Facades\Schema::hasTable('stock_movements') && \Illuminate\Support\Facades\Schema::hasColumn('stock_movements', 'ingredient_id')) {
            $hasIngredientMovements = \Illuminate\Support\Facades\DB::table('stock_movements')
                ->where('ingredient_id', $ingredient->id)
                ->exists();
            if ($hasIngredientMovements) {
                $reasons[] = 'mutasi kartu stok';
            }
        }

        // 6. Cek riwayat transaksi pembelian (faktur pembelian / PO)
        if (\Illuminate\Support\Facades\Schema::hasTable('purchase_items') && \Illuminate\Support\Facades\Schema::hasColumn('purchase_items', 'ingredient_id')) {
            $hasPurchases = \Illuminate\Support\Facades\DB::table('purchase_items')
                ->where('ingredient_id', $ingredient->id)
                ->exists();
            if ($hasPurchases) {
                $reasons[] = 'transaksi pembelian/faktur masuk';
            }
        }

        // 7. Cek riwayat waste / susut
        if (\Illuminate\Support\Facades\Schema::hasTable('waste_logs') && \Illuminate\Support\Facades\Schema::hasColumn('waste_logs', 'ingredient_id')) {
            $hasWaste = \Illuminate\Support\Facades\DB::table('waste_logs')
                ->where('ingredient_id', $ingredient->id)
                ->exists();
            if ($hasWaste) {
                $reasons[] = 'pencatatan waste/susut';
            }
        }

        // 8. Cek riwayat stock opname
        if (\Illuminate\Support\Facades\Schema::hasTable('stock_opname_items') && \Illuminate\Support\Facades\Schema::hasColumn('stock_opname_items', 'ingredient_id')) {
            $hasOpname = \Illuminate\Support\Facades\DB::table('stock_opname_items')
                ->where('ingredient_id', $ingredient->id)
                ->exists();
            if ($hasOpname) {
                $reasons[] = 'riwayat stock opname';
            }
        }

        // 9. Cek riwayat transfer cabang
        if (\Illuminate\Support\Facades\Schema::hasTable('transfer_items') && \Illuminate\Support\Facades\Schema::hasColumn('transfer_items', 'ingredient_id')) {
            $hasTransfer = \Illuminate\Support\Facades\DB::table('transfer_items')
                ->where('ingredient_id', $ingredient->id)
                ->exists();
            if ($hasTransfer) {
                $reasons[] = 'riwayat transfer stok';
            }
        }

        // 10. Cek riwayat saldo awal
        if (\Illuminate\Support\Facades\Schema::hasTable('ingredient_initial_balances')) {
            $hasInit = \Illuminate\Support\Facades\DB::table('ingredient_initial_balances')
                ->where('ingredient_id', $ingredient->id)
                ->where(function($q) {
                    $q->where('stok_awal', '!=', 0)->orWhere('saldo_awal_nominal', '!=', 0);
                })
                ->exists();
            if ($hasInit) {
                $reasons[] = 'saldo awal persediaan';
            }
        }

        if (!empty($reasons)) {
            $isPerl = str_starts_with(strtoupper((string)$ingredient->code), 'PLK-') ||
                str_contains(strtolower((string)$ingredient->category), 'perlengkapan') ||
                str_contains(strtolower((string)$ingredient->category), 'kemasan') ||
                str_contains(strtolower((string)$ingredient->category), 'cup') ||
                str_contains(strtolower((string)$ingredient->category), 'sedotan') ||
                str_contains(strtolower((string)$ingredient->category), 'packaging');
            $kategoriLabel = $isPerl ? 'Perlengkapan' : 'Bahan Baku';

            $reasonStr = implode(', ', array_unique($reasons));
            return "Dilarang menghapus {$kategoriLabel} '{$ingredient->name}' ({$ingredient->code})! Data ini sudah digunakan dalam {$reasonStr}. Menghapus data ini akan menghilangkan riwayat transaksi dan merusak integritas kartu stok serta laporan laba rugi. Jika sudah tidak digunakan lagi, silakan nonaktifkan item ini (ubah status menjadi Tidak Aktif).";
        }

        return null;
    }

    public function destroy(Ingredient $ingredient)
    {
        $blockReason = $this->getDeletionBlockReason($ingredient);
        if ($blockReason) {
            return response()->json([
                'message' => $blockReason,
                'error'   => 'ITEM_LOCKED_BY_TRANSACTIONS',
            ], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($ingredient) {
            $this->cleanupIngredientRelations([$ingredient->id]);
            $ingredient->delete();
        });

        return response()->json(['message' => 'Data berhasil dihapus.']);
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $user = $request->user();
        $businessId = $user?->business_id;
        $ids = $request->input('ids', []);

        $query = Ingredient::whereIn('id', $ids);
        if ($businessId) {
            $query->where('business_id', $businessId);
        }
        $ingredients = $query->get();

        if ($ingredients->isEmpty()) {
            return response()->json(['message' => 'Tidak ada data bahan yang ditemukan untuk dihapus.'], 404);
        }

        // Validasi: Cek apakah ada bahan/perlengkapan yang dilarang dihapus karena digunakan di master menu dan sudah ada transaksi
        $blockedList = [];
        foreach ($ingredients as $ing) {
            $reason = $this->getDeletionBlockReason($ing);
            if ($reason) {
                $blockedList[] = $reason;
            }
        }

        if (!empty($blockedList)) {
            $countBlocked = count($blockedList);
            $sampleErrors = implode("\n\n", array_slice($blockedList, 0, 3));
            if ($countBlocked > 3) {
                $sampleErrors .= "\n\n... dan " . ($countBlocked - 3) . " item lainnya juga dilarang dihapus.";
            }

            return response()->json([
                'message' => "Operasi dibatalkan! Terdapat {$countBlocked} item yang dilarang dihapus karena terhubung ke Master Menu & riwayat transaksi:\n\n{$sampleErrors}",
                'error'   => 'ITEMS_LOCKED_BY_TRANSACTIONS',
                'blocked_count' => $countBlocked,
            ], 422);
        }

        $deletedCount = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($ingredients, &$deletedCount) {
            $validIds = $ingredients->pluck('id')->toArray();
            $this->cleanupIngredientRelations($validIds);

            foreach ($ingredients as $ing) {
                $ing->delete();
                $deletedCount++;
            }
        });

        return response()->json([
            'message'       => "Berhasil menghapus {$deletedCount} data master.",
            'deleted_count' => $deletedCount,
        ]);
    }

    public function bulkImport(Request $request)
    {
        $user = $request->user();
        $businessId = $user?->business_id;

        $items = $request->input('items', []);
        if (empty($items) || !is_array($items)) {
            return response()->json(['message' => 'Data import kosong atau tidak valid.'], 422);
        }

        $importedCount = 0;
        $globalEffectiveDate = $request->input('effective_date', $request->input('date'));

        try {
            DB::transaction(function () use ($items, $businessId, $user, $globalEffectiveDate, &$importedCount) {
                $businessOutlets = \App\Models\Outlet::where('business_id', $businessId)->get();
                if ($businessOutlets->isEmpty()) {
                    $businessOutlets = \App\Models\Outlet::all();
                }

                foreach ($items as $idx => $row) {
                    if (empty($row['name'])) continue;

                    $name = trim($row['name']);
                    $lowerName = strtolower($name);

                    // Filter out accidental header / banner rows
                    if (
                        str_starts_with($name, '===') ||
                        str_contains($lowerName, 'template import') ||
                        str_contains($lowerName, 'petunjuk') ||
                        str_contains($lowerName, 'data bahan') ||
                        in_array($lowerName, ['kode bahan', 'nama bahan', 'nama bahan*', 'kategori', 'tipe bahan', 'satuan beli', 'satuan pakai'])
                    ) {
                        continue;
                    }

                    $code = !empty($row['code']) ? trim($row['code']) : null;
                    $name = trim($row['name']);

                    // If user didn't specify a code, auto-generate next safe code for this business without collision
                    if (empty($code)) {
                        $seq = Ingredient::where('business_id', $businessId)->count() + 1;
                        do {
                            $candidateCode = 'BHN-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                            $exists = Ingredient::where('business_id', $businessId)->where('code', $candidateCode)->exists();
                            $seq++;
                        } while ($exists);
                        $code = $candidateCode;
                    }

                    $categoryName = !empty($row['category']) ? trim($row['category']) : 'BAHAN_BAKU';
                    $cat = Category::firstOrCreate(
                        ['business_id' => $businessId, 'name' => $categoryName, 'type' => 'INGREDIENT'],
                        ['slug' => Str::slug($categoryName), 'color' => '#00B14F', 'icon' => 'Package']
                    );

                    // Smart Unit Normalizer: auto typo-fix, synonym mapping, and auto-register new unit
                    $unitBeliInfo = $this->resolveUnit($row['unit_beli'] ?? 'kg', $businessId, 'kg');
                    $unitPakaiInfo = $this->resolveUnit($row['unit_pakai'] ?? 'gram', $businessId, 'gram');

                    // Smart Conversion Auto-Fix (if user omitted or entered 1 for different units)
                    $konversi = (float)($row['konversi'] ?? 0);
                    if ($konversi <= 0 || ($konversi == 1 && $unitBeliInfo['symbol'] !== $unitPakaiInfo['symbol'])) {
                        if ($unitBeliInfo['symbol'] === 'kg' && $unitPakaiInfo['symbol'] === 'gram') {
                            $konversi = 1000;
                        } elseif ($unitBeliInfo['symbol'] === 'liter' && $unitPakaiInfo['symbol'] === 'ml') {
                            $konversi = 1000;
                        } elseif ($unitBeliInfo['symbol'] === 'slop' && $unitPakaiInfo['symbol'] === 'pcs') {
                            $konversi = 50;
                        } elseif ($unitBeliInfo['symbol'] === 'pack' && in_array($unitPakaiInfo['symbol'], ['pcs', 'lembar'])) {
                            $konversi = ($unitPakaiInfo['symbol'] === 'lembar') ? 200 : 100;
                        } else {
                            $konversi = ($konversi <= 0) ? 1 : $konversi;
                        }
                    }

                    $hargaBeli = (float)($row['harga'] ?? 0);
                    $minStok = (float)($row['minstok'] ?? $row['stok_min'] ?? 0);
                    $initialStock = (float)(
                        $row['initial_stock'] ??
                        $row['stock_awal'] ??
                        $row['stok_awal'] ??
                        $row['initialStock'] ??
                        $row['stock'] ??
                        $row['stok'] ??
                        0
                    );
                    $initialBalance = round((float)($row['initial_balance'] ?? $row['saldo_awal_nominal'] ?? $row['saldo_awal'] ?? 0));
                    if ($hargaBeli <= 0 && $initialStock > 0 && $initialBalance > 0) {
                        $hargaBeli = round(($initialBalance / $initialStock) * $konversi, 2);
                    }

                    // Parse Effective Date (Tanggal Mulai Saldo Awal)
                    $rawDate = $row['date'] ?? $row['tanggal_mulai'] ?? $row['tanggal_efektif'] ?? $row['effective_date'] ?? $row['tanggal'] ?? $globalEffectiveDate ?? null;
                    $effectiveDate = null;
                    if (!empty($rawDate)) {
                        try {
                            if (is_numeric($rawDate) && (float)$rawDate > 20000 && (float)$rawDate < 80000) {
                                $effectiveDate = \Carbon\Carbon::createFromTimestampUTC(((float)$rawDate - 25569) * 86400)->toDateString();
                            } else {
                                $effectiveDate = \Carbon\Carbon::parse($rawDate)->toDateString();
                            }
                        } catch (\Throwable $e) {}
                    }

                    // Match strictly by (business_id, code) so custom codes are honored per business
                    $existingIng = Ingredient::where('business_id', $businessId)->where('code', $code)->first();

                    $ingData = [
                        'name'                => $name,
                        'category_id'         => $cat->id,
                        'category'            => $cat->name,
                        'type'                => !empty($row['type']) ? strtoupper($row['type']) : 'RAW',
                        'unit_beli'           => substr($unitBeliInfo['symbol'], 0, 20),
                        'unit_pakai'          => substr($unitPakaiInfo['symbol'], 0, 20),
                        'unit_beli_id'        => $unitBeliInfo['id'],
                        'unit_pakai_id'       => $unitPakaiInfo['id'],
                        'konversi'            => $konversi,
                        'stok_min'            => $minStok,
                        'tolerance'           => (float)($row['tolerance'] ?? 5),
                        'notes'               => $row['notes'] ?? 'Imported from Excel',
                        'created_by'          => $user?->id,
                        'updated_by'          => $user?->id,
                    ];

                    if ($hargaBeli > 0 || !$existingIng) {
                        $ingData['harga'] = $hargaBeli;
                        $ingData['last_purchase_price'] = $hargaBeli;
                    }

                    $ing = Ingredient::updateOrCreate(
                        [
                            'business_id' => $businessId,
                            'code'        => substr($code, 0, 20),
                        ],
                        $ingData
                    );

                    $targetOutletName = trim($row['outlet_name'] ?? $row['outlet'] ?? $row['cabang'] ?? '');
                    $matchedOutlet = null;
                    if (!empty($targetOutletName)) {
                        $matchedOutlet = $businessOutlets->first(function ($o) use ($targetOutletName) {
                            return strcasecmp(trim($o->name), $targetOutletName) === 0 ||
                                   strcasecmp(trim($o->code), $targetOutletName) === 0 ||
                                   str_contains(strtolower($o->name), strtolower($targetOutletName));
                        });
                    }

                    $targetOutletId = null;
                    if ($matchedOutlet) {
                        $targetOutletId = (int)$matchedOutlet->id;
                    } elseif ($user?->outlet_id && $businessOutlets->contains('id', $user->outlet_id)) {
                        $targetOutletId = (int)$user->outlet_id;
                    } else {
                        $mainOut = $businessOutlets->firstWhere('is_main', true) ?? $businessOutlets->first();
                        $targetOutletId = $mainOut ? (int)$mainOut->id : 1;
                    }

                    // Sync/Initialize OutletIngredient for all business outlets
                    foreach ($businessOutlets as $bo) {
                        $outletRow = \App\Models\OutletIngredient::firstOrNew([
                            'outlet_id'     => $bo->id,
                            'ingredient_id' => $ing->id,
                        ]);
                        $isTarget = ((int)$bo->id === (int)$targetOutletId);
                        $rowStokAwal = ($initialStock > 0 && $isTarget) ? $initialStock : 0.0;
                        $rowNominal = ($initialStock > 0 && $isTarget && $hargaBeli > 0)
                            ? round($initialStock * ($hargaBeli / max((float)($ingData['konversi'] ?? 1), 1)), 2)
                            : 0.0;

                        $outletRow->stok_min = $minStok;

                        if ($hargaBeli > 0 || $outletRow->harga === null) {
                            $outletRow->harga = $hargaBeli;
                            $outletRow->last_purchase_price = $hargaBeli;
                        }
                        $outletRow->save();

                        // Table 2: Dedicated Saldo Awal
                        \App\Models\IngredientInitialBalance::updateOrCreate(
                            [
                                'outlet_id'     => $bo->id,
                                'ingredient_id' => $ing->id,
                            ],
                            [
                                'business_id'        => $businessId,
                                'stok_awal'          => $rowStokAwal,
                                'saldo_awal_nominal' => $rowNominal,
                                'tanggal_saldo_awal' => $effectiveDate,
                                'created_by'         => $user?->id,
                                'updated_by'         => $user?->id,
                            ]
                        );
                    }

                    $importedCount++;
                }
            });

            if ($businessId && $importedCount > 0) {
                try {
                    $biz = \App\Models\Business::find($businessId);
                    if ($biz) {
                        \App\Services\AccountingService::postInitialBalance($biz);
                    }
                } catch (\Throwable $accEx) {
                    \Illuminate\Support\Facades\Log::error("postInitialBalance after ingredient import error: " . $accEx->getMessage());
                }
            }
        } catch (\Throwable $e) {
            try {
                \Illuminate\Support\Facades\Log::error("bulkImport Ingredient error: " . $e->getMessage());
            } catch (\Throwable $logEx) {}

            return response()->json([
                'message' => 'Gagal meng-import master bahan: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => "Berhasil meng-import {$importedCount} master bahan terpusat.",
            'imported_count' => $importedCount,
        ]);
    }

    public function bulkImportPerlengkapan(Request $request)
    {
        $user = $request->user();
        $businessId = $user?->business_id;

        $items = $request->input('items', []);
        if (empty($items) || !is_array($items)) {
            return response()->json(['message' => 'Data import perlengkapan kosong atau tidak valid.'], 422);
        }

        $importedCount = 0;
        $globalEffectiveDate = $request->input('effective_date', $request->input('date'));

        try {
            DB::transaction(function () use ($items, $businessId, $user, $globalEffectiveDate, &$importedCount) {
                $businessOutlets = \App\Models\Outlet::where('business_id', $businessId)->get();
                if ($businessOutlets->isEmpty()) {
                    $businessOutlets = \App\Models\Outlet::all();
                }

                foreach ($items as $idx => $row) {
                    if (empty($row['name'])) continue;

                    $name = trim($row['name']);
                    $lowerName = strtolower($name);

                    if (
                        str_starts_with($name, '===') ||
                        str_contains($lowerName, 'template import') ||
                        str_contains($lowerName, 'petunjuk') ||
                        str_contains($lowerName, 'data perlengkapan') ||
                        in_array($lowerName, ['kode perlengkapan', 'nama perlengkapan', 'nama perlengkapan*', 'kategori', 'satuan beli', 'satuan pakai'])
                    ) {
                        continue;
                    }

                    $code = !empty($row['code']) ? trim($row['code']) : null;

                    // If user didn't specify a code, auto-generate next safe code for this business without collision
                    if (empty($code)) {
                        $seq = Ingredient::where('business_id', $businessId)->count() + 1;
                        do {
                            $candidateCode = 'PLK-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                            $exists = Ingredient::where('business_id', $businessId)->where('code', $candidateCode)->exists();
                            $seq++;
                        } while ($exists);
                        $code = $candidateCode;
                    }

                    $categoryName = !empty($row['category']) ? trim($row['category']) : 'Perlengkapan';
                    $cat = Category::firstOrCreate(
                        ['business_id' => $businessId, 'name' => $categoryName, 'type' => 'INGREDIENT'],
                        ['slug' => Str::slug($categoryName), 'color' => '#00B14F', 'icon' => 'Package']
                    );

                    // Smart Unit Normalizer: auto typo-fix, synonym mapping, and auto-register new unit
                    $unitBeliInfo = $this->resolveUnit($row['unit_beli'] ?? 'Slop', $businessId, 'Slop');
                    $unitPakaiInfo = $this->resolveUnit($row['unit_pakai'] ?? 'pcs', $businessId, 'pcs');

                    // Smart Conversion Auto-Fix
                    $konversi = (float)($row['konversi'] ?? 0);
                    if ($konversi <= 0 || ($konversi == 1 && $unitBeliInfo['symbol'] !== $unitPakaiInfo['symbol'])) {
                        if ($unitBeliInfo['symbol'] === 'slop' && $unitPakaiInfo['symbol'] === 'pcs') {
                            $konversi = 50;
                        } elseif ($unitBeliInfo['symbol'] === 'pack' && in_array($unitPakaiInfo['symbol'], ['pcs', 'lembar'])) {
                            $konversi = ($unitPakaiInfo['symbol'] === 'lembar') ? 200 : 100;
                        } elseif ($unitBeliInfo['symbol'] === 'kg' && $unitPakaiInfo['symbol'] === 'gram') {
                            $konversi = 1000;
                        } else {
                            $konversi = ($konversi <= 0) ? 1 : $konversi;
                        }
                    }

                    $hargaBeli = (float)($row['harga'] ?? 0);
                    $minStok = (float)($row['minstok'] ?? $row['stok_min'] ?? 0);
                    $initialStock = (float)(
                        $row['initial_stock'] ??
                        $row['stock_awal'] ??
                        $row['stok_awal'] ??
                        $row['initialStock'] ??
                        $row['stock'] ??
                        $row['stok'] ??
                        0
                    );
                    $initialBalance = round((float)($row['initial_balance'] ?? $row['saldo_awal_nominal'] ?? $row['saldo_awal'] ?? 0));
                    if ($hargaBeli <= 0 && $initialStock > 0 && $initialBalance > 0) {
                        $hargaBeli = round(($initialBalance / $initialStock) * $konversi, 2);
                    }

                    // Parse Effective Date (Tanggal Mulai Saldo Awal)
                    $rawDate = $row['date'] ?? $row['tanggal_mulai'] ?? $row['tanggal_efektif'] ?? $row['effective_date'] ?? $row['tanggal'] ?? $globalEffectiveDate ?? null;
                    $effectiveDate = null;
                    if (!empty($rawDate)) {
                        try {
                            if (is_numeric($rawDate) && (float)$rawDate > 20000 && (float)$rawDate < 80000) {
                                $effectiveDate = \Carbon\Carbon::createFromTimestampUTC(((float)$rawDate - 25569) * 86400)->toDateString();
                            } else {
                                $effectiveDate = \Carbon\Carbon::parse($rawDate)->toDateString();
                            }
                        } catch (\Throwable $e) {}
                    }

                    // Match strictly by (business_id, code) so custom codes are honored per business
                    $existingIng = Ingredient::where('business_id', $businessId)->where('code', $code)->first();

                    $ingData = [
                        'name'                => $name,
                        'category_id'         => $cat->id,
                        'category'            => $cat->name,
                        'type'                => 'RAW',
                        'unit_beli'           => substr($unitBeliInfo['symbol'], 0, 20),
                        'unit_pakai'          => substr($unitPakaiInfo['symbol'], 0, 20),
                        'unit_beli_id'        => $unitBeliInfo['id'],
                        'unit_pakai_id'       => $unitPakaiInfo['id'],
                        'konversi'            => $konversi,
                        'stok_min'            => $minStok,
                        'tolerance'           => (float)($row['tolerance'] ?? 5),
                        'notes'               => $row['notes'] ?? 'Imported Perlengkapan from Excel',
                        'created_by'          => $user?->id,
                        'updated_by'          => $user?->id,
                    ];

                    if ($hargaBeli > 0 || !$existingIng) {
                        $ingData['harga'] = $hargaBeli;
                        $ingData['last_purchase_price'] = $hargaBeli;
                    }

                    $ing = Ingredient::updateOrCreate(
                        [
                            'business_id' => $businessId,
                            'code'        => substr($code, 0, 20),
                        ],
                        $ingData
                    );

                    $targetOutletName = trim($row['outlet_name'] ?? $row['outlet'] ?? $row['cabang'] ?? '');
                    $matchedOutlet = null;
                    if (!empty($targetOutletName)) {
                        $matchedOutlet = $businessOutlets->first(function ($o) use ($targetOutletName) {
                            return strcasecmp(trim($o->name), $targetOutletName) === 0 ||
                                   strcasecmp(trim($o->code), $targetOutletName) === 0 ||
                                   str_contains(strtolower($o->name), strtolower($targetOutletName));
                        });
                    }

                    $targetOutletId = null;
                    if ($matchedOutlet) {
                        $targetOutletId = (int)$matchedOutlet->id;
                    } elseif ($user?->outlet_id && $businessOutlets->contains('id', $user->outlet_id)) {
                        $targetOutletId = (int)$user->outlet_id;
                    } else {
                        $mainOut = $businessOutlets->firstWhere('is_main', true) ?? $businessOutlets->first();
                        $targetOutletId = $mainOut ? (int)$mainOut->id : 1;
                    }

                    // Sync/Initialize OutletIngredient for all business outlets
                    foreach ($businessOutlets as $bo) {
                        $outletRow = \App\Models\OutletIngredient::firstOrNew([
                            'outlet_id'     => $bo->id,
                            'ingredient_id' => $ing->id,
                        ]);
                        $isTarget = ((int)$bo->id === (int)$targetOutletId);
                        $rowStokAwal = ($initialStock > 0 && $isTarget) ? $initialStock : 0.0;
                        $rowNominal = ($initialStock > 0 && $isTarget && $hargaBeli > 0)
                            ? round($initialStock * ($hargaBeli / max((float)($ingData['konversi'] ?? 1), 1)), 2)
                            : 0.0;

                        $outletRow->stok_min = $minStok;

                        if ($hargaBeli > 0 || $outletRow->harga === null) {
                            $outletRow->harga = $hargaBeli;
                            $outletRow->last_purchase_price = $hargaBeli;
                        }
                        $outletRow->save();

                        // Table 2: Dedicated Saldo Awal
                        \App\Models\IngredientInitialBalance::updateOrCreate(
                            [
                                'outlet_id'     => $bo->id,
                                'ingredient_id' => $ing->id,
                            ],
                            [
                                'business_id'        => $businessId,
                                'stok_awal'          => $rowStokAwal,
                                'saldo_awal_nominal' => $rowNominal,
                                'tanggal_saldo_awal' => $effectiveDate,
                                'created_by'         => $user?->id,
                                'updated_by'         => $user?->id,
                            ]
                        );
                    }

                    $importedCount++;
                }
            });

            if ($businessId && $importedCount > 0) {
                try {
                    $biz = \App\Models\Business::find($businessId);
                    if ($biz) {
                        \App\Services\AccountingService::postInitialBalance($biz);
                    }
                } catch (\Throwable $accEx) {
                    \Illuminate\Support\Facades\Log::error("postInitialBalance after perlengkapan import error: " . $accEx->getMessage());
                }
            }
        } catch (\Throwable $e) {
            try {
                \Illuminate\Support\Facades\Log::error("bulkImportPerlengkapan error: " . $e->getMessage());
            } catch (\Throwable $logEx) {}

            return response()->json([
                'message' => 'Gagal meng-import master perlengkapan: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => "Berhasil meng-import {$importedCount} data master perlengkapan & packaging terpusat.",
            'imported_count' => $importedCount,
        ]);
    }

    /**
     * Smart Unit Normalizer & Auto-Resolver
     * - Sanitizes string (lowercase, trim, strip punctuation)
     * - Maps known typos and synonyms (e.g. kgg -> kg, grram -> gram, ltr -> liter, btl -> botol)
     * - Fuzzy matching (Levenshtein distance <= 1)
     * - Checks existing units in database
     * - Auto-creates and registers new custom unit in 'units' table if not existing
     *
     * @return array [string 'symbol', string 'name', int 'id']
     */
    public static function resolveUnit(?string $rawUnit, ?int $businessId = null, string $default = 'pcs'): array
    {
        $raw = trim($rawUnit ?? '');
        if ($raw === '') {
            $raw = $default;
        }

        // Clean: strip trailing dots, multi-spaces, non-alphanumeric except hyphen/space
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $raw));
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);

        // Alias and typo dictionary
        $aliasMap = [
            // Kilogram
            'kg' => 'kg', 'kgg' => 'kg', 'kilo' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg', 'kgs' => 'kg',
            // Gram
            'gram' => 'gram', 'gr' => 'gram', 'g' => 'gram', 'gramm' => 'gram', 'grm' => 'gram', 'grams' => 'gram',
            // Liter
            'liter' => 'liter', 'ltr' => 'liter', 'lt' => 'liter', 'liters' => 'liter', 'l' => 'liter',
            // Mililiter
            'ml' => 'ml', 'mll' => 'ml', 'mililiter' => 'ml', 'milliliter' => 'ml', 'cc' => 'ml',
            // Pieces / Buah
            'pcs' => 'pcs', 'pc' => 'pcs', 'pcss' => 'pcs', 'piece' => 'pcs', 'pieces' => 'pcs', 
            'buah' => 'pcs', 'biji' => 'pcs', 'butir' => 'pcs', 'btr' => 'pcs',
            // Lembar
            'lembar' => 'lembar', 'lbr' => 'lembar', 'sheet' => 'lembar', 'sheets' => 'lembar',
            // Slop
            'slop' => 'slop', 'slp' => 'slop', 'slopp' => 'slop',
            // Pack
            'pack' => 'pack', 'pck' => 'pack', 'pak' => 'pack', 'paket' => 'pack', 'pax' => 'pack',
            // Roll
            'roll' => 'roll', 'rol' => 'roll', 'gulung' => 'roll',
            // Botol
            'botol' => 'botol', 'btl' => 'botol', 'bottle' => 'botol', 'bottles' => 'botol',
            // Cup
            'cup' => 'cup', 'gelas' => 'cup', 'cangkir' => 'cup',
            // Dus / Karton
            'dus' => 'dus', 'karton' => 'dus', 'kardus' => 'dus', 'ctn' => 'dus', 'box' => 'dus',
            // Kaleng
            'can' => 'can', 'kaleng' => 'can', 'klg' => 'can', 'tin' => 'can',
            // Sachet
            'sachet' => 'sachet', 'sct' => 'sachet', 'bungkus' => 'sachet', 'bks' => 'sachet',
            // Porsi
            'porsi' => 'porsi', 'portion' => 'porsi', 'prs' => 'porsi',
            // Sendok Makan
            'sdm' => 'sdm', 'sendok makan' => 'sdm', 'tbsp' => 'sdm',
            // Sendok Teh
            'sdt' => 'sdt', 'sendok teh' => 'sdt', 'tsp' => 'sdt',
        ];

        $canonicalSymbol = $aliasMap[$clean] ?? null;

        // Fuzzy typo matching against known standard keys if close (distance <= 1)
        if (!$canonicalSymbol) {
            $standardSymbols = ['kg', 'gram', 'liter', 'ml', 'pcs', 'lembar', 'slop', 'pack', 'roll', 'botol', 'cup', 'dus', 'can', 'sachet', 'porsi', 'sdm', 'sdt'];
            foreach ($standardSymbols as $sym) {
                if (levenshtein($clean, $sym) <= 1) {
                    $canonicalSymbol = $sym;
                    break;
                }
            }
        }

        // If still no canonical match, use the cleaned original as custom unit symbol
        if (!$canonicalSymbol) {
            $canonicalSymbol = $clean;
        }

        // Standard friendly name dictionary
        $nameMap = [
            'kg'     => 'Kilogram',
            'gram'   => 'Gram',
            'liter'  => 'Liter',
            'ml'     => 'Mililiter',
            'pcs'    => 'Pieces',
            'lembar' => 'Lembar',
            'slop'   => 'Slop',
            'pack'   => 'Pack',
            'roll'   => 'Roll',
            'botol'  => 'Botol',
            'cup'    => 'Cup',
            'dus'    => 'Dus / Karton',
            'can'    => 'Kaleng / Can',
            'sachet' => 'Sachet',
            'porsi'  => 'Porsi',
            'sdm'    => 'Sendok Makan',
            'sdt'    => 'Sendok Teh',
        ];

        $unitName = $nameMap[$canonicalSymbol] ?? ucwords(str_replace('_', ' ', $raw));

        // Find existing unit in database (by symbol or name)
        $unitQuery = Unit::query();
        if ($businessId) {
            $unitQuery->where(function($q) use ($businessId) {
                $q->where('business_id', $businessId)->orWhereNull('business_id');
            });
        }
        $existingUnit = (clone $unitQuery)->where(function ($q) use ($canonicalSymbol, $unitName) {
            $q->where('symbol', $canonicalSymbol)
              ->orWhere('name', $unitName)
              ->orWhereRaw('LOWER(name) = ?', [strtolower($unitName)])
              ->orWhereRaw('LOWER(symbol) = ?', [strtolower($canonicalSymbol)]);
        })->first();

        if ($existingUnit) {
            return [
                'symbol' => $existingUnit->symbol,
                'name'   => $existingUnit->name,
                'id'     => $existingUnit->id,
            ];
        }

        // Auto-register new unit into database!
        $newUnit = Unit::create([
            'business_id'  => $businessId,
            'name'         => $unitName,
            'symbol'       => $canonicalSymbol,
            'is_base_unit' => in_array($canonicalSymbol, ['gram', 'ml', 'pcs', 'lembar', 'porsi']),
        ]);

        return [
            'symbol' => $newUnit->symbol,
            'name'   => $newUnit->name,
            'id'     => $newUnit->id,
        ];
    }
}
