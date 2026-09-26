<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $query = Ingredient::with(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements', 'categoryModel'])
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

        // ⚡ Explicitly append stock and outlet-isolated pricing attributes
        $ingredients->each(function ($ing) {
            $ing->append(['current_stock', 'current_stok_min', 'current_harga', 'outlet_stocks']);
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

        $data['last_purchase_price'] = $data['harga'] ?? null;
        $ingredient = Ingredient::create($data);

        // Sync to OutletIngredient for all business outlets
        $businessOutlets = \App\Models\Outlet::where('business_id', $businessId)->get();
        if ($businessOutlets->isEmpty()) {
            $businessOutlets = \App\Models\Outlet::all();
        }
        foreach ($businessOutlets as $bo) {
            \App\Models\OutletIngredient::updateOrCreate(
                [
                    'outlet_id'     => $bo->id,
                    'ingredient_id' => $ingredient->id,
                ],
                [
                    'stok_awal'           => ($bo->is_main || (int)$bo->id === 1 || $user?->outlet_id === $bo->id) ? (float)($data['stok_awal'] ?? 0) : 0,
                    'stok_min'            => (float)($data['stok_min'] ?? 0),
                    'harga'               => (float)($data['harga'] ?? 0),
                    'last_purchase_price' => (float)($data['harga'] ?? 0),
                ]
            );
        }

        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements', 'categoryModel']);
        $ingredient->append(['current_stock', 'current_stok_min', 'current_harga', 'outlet_stocks']);
        return response()->json($ingredient, 201);
    }

    public function show(Ingredient $ingredient)
    {
        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements', 'categoryModel']);
        $ingredient->append(['current_stock', 'current_stok_min', 'current_harga', 'outlet_stocks']);
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
        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements', 'categoryModel']);
        $ingredient->append(['current_stock', 'current_stok_min', 'outlet_stocks']);
        return response()->json($ingredient);
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

    public function destroy(Ingredient $ingredient)
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($ingredient) {
            $this->cleanupIngredientRelations([$ingredient->id]);
            $ingredient->delete();
        });

        return response()->json(['message' => 'Bahan berhasil dihapus.']);
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

        DB::transaction(function () use ($items, $businessId, $user, &$importedCount) {
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
                $initialStock = (float)($row['initial_stock'] ?? $row['stok_awal'] ?? 0);

                // Match strictly by (business_id, code) so custom codes are honored per business
                $ing = Ingredient::updateOrCreate(
                    [
                        'business_id' => $businessId,
                        'code'        => $code,
                    ],
                    [
                        'name'                => $name,
                        'category_id'         => $cat->id,
                        'category'            => $cat->name,
                        'type'                => !empty($row['type']) ? strtoupper($row['type']) : 'RAW',
                        'unit_beli'           => $unitBeliInfo['symbol'],
                        'unit_pakai'          => $unitPakaiInfo['symbol'],
                        'unit_beli_id'        => $unitBeliInfo['id'],
                        'unit_pakai_id'       => $unitPakaiInfo['id'],
                        'konversi'            => $konversi,
                        'harga'               => $hargaBeli,
                        'last_purchase_price' => $hargaBeli,
                        'stok_min'            => $minStok,
                        'stok_awal'           => $initialStock,
                        'notes'               => $row['notes'] ?? 'Imported from Excel',
                        'created_by'          => $user?->id,
                        'updated_by'          => $user?->id,
                    ]
                );

                // Sync/Initialize OutletIngredient for all business outlets
                foreach ($businessOutlets as $bo) {
                    $outletRow = \App\Models\OutletIngredient::firstOrNew([
                        'outlet_id'     => $bo->id,
                        'ingredient_id' => $ing->id,
                    ]);
                    if ($bo->is_main || (int)$bo->id === 1 || $user?->outlet_id === $bo->id) {
                        $outletRow->stok_awal = $initialStock;
                    } elseif ($outletRow->stok_awal === null) {
                        $outletRow->stok_awal = 0.0;
                    }
                    if ($minStok > 0 || $outletRow->stok_min === null) {
                        $outletRow->stok_min = $minStok;
                    }
                    if ($hargaBeli > 0 || $outletRow->harga === null) {
                        $outletRow->harga = $hargaBeli;
                        $outletRow->last_purchase_price = $hargaBeli;
                    }
                    $outletRow->save();
                }

                $importedCount++;
            }
        });

        return response()->json([
            'message' => "Berhasil meng-import {$importedCount} master bahan lengkap beserta saldo awal dan harga.",
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

        DB::transaction(function () use ($items, $businessId, $user, &$importedCount) {
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
                $initialStock = (float)($row['initial_stock'] ?? $row['stok_awal'] ?? 0);

                // Match strictly by (business_id, code) so custom codes are honored per business
                $ing = Ingredient::updateOrCreate(
                    [
                        'business_id' => $businessId,
                        'code'        => $code,
                    ],
                    [
                        'name'                => $name,
                        'category_id'         => $cat->id,
                        'category'            => $cat->name,
                        'type'                => 'RAW',
                        'unit_beli'           => $unitBeliInfo['symbol'],
                        'unit_pakai'          => $unitPakaiInfo['symbol'],
                        'unit_beli_id'        => $unitBeliInfo['id'],
                        'unit_pakai_id'       => $unitPakaiInfo['id'],
                        'konversi'            => $konversi,
                        'harga'               => $hargaBeli,
                        'last_purchase_price' => $hargaBeli,
                        'stok_min'            => $minStok,
                        'stok_awal'           => $initialStock,
                        'tolerance'           => (float)($row['tolerance'] ?? 5),
                        'notes'               => $row['notes'] ?? 'Imported Perlengkapan from Excel',
                        'created_by'          => $user?->id,
                        'updated_by'          => $user?->id,
                    ]
                );

                // Sync/Initialize OutletIngredient for all business outlets
                foreach ($businessOutlets as $bo) {
                    $outletRow = \App\Models\OutletIngredient::firstOrNew([
                        'outlet_id'     => $bo->id,
                        'ingredient_id' => $ing->id,
                    ]);
                    if ($bo->is_main || (int)$bo->id === 1 || $user?->outlet_id === $bo->id) {
                        $outletRow->stok_awal = $initialStock;
                    } elseif ($outletRow->stok_awal === null) {
                        $outletRow->stok_awal = 0.0;
                    }
                    if ($minStok > 0 || $outletRow->stok_min === null) {
                        $outletRow->stok_min = $minStok;
                    }
                    if ($hargaBeli > 0 || $outletRow->harga === null) {
                        $outletRow->harga = $hargaBeli;
                        $outletRow->last_purchase_price = $hargaBeli;
                    }
                    $outletRow->save();
                }

                $importedCount++;
            }
        });

        return response()->json([
            'message' => "Berhasil meng-import {$importedCount} data master perlengkapan lengkap beserta saldo awal dan harga.",
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
    protected function resolveUnit(?string $rawUnit, ?int $businessId, string $default = 'pcs'): array
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
