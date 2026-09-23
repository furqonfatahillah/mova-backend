<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Ingredient;
use Illuminate\Http\Request;
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

        $ingredient = Ingredient::create($data);
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

        $data['updated_by'] = $request->user()?->id;

        $ingredient->update($data);
        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements', 'categoryModel']);
        $ingredient->append(['current_stock', 'current_stok_min', 'outlet_stocks']);
        return response()->json($ingredient);
    }

    public function destroy(Ingredient $ingredient)
    {
        $ingredient->delete();
        return response()->json(['message' => 'Deleted']);
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
            foreach ($items as $idx => $row) {
                if (empty($row['name'])) continue;

                $code = !empty($row['code']) ? trim($row['code']) : ('BHN-' . str_pad($idx + 1 + Ingredient::where('business_id', $businessId)->count(), 3, '0', STR_PAD_LEFT));
                $name = trim($row['name']);

                $categoryName = !empty($row['category']) ? trim($row['category']) : 'BAHAN_BAKU';
                $cat = Category::firstOrCreate(
                    ['business_id' => $businessId, 'name' => $categoryName, 'type' => 'INGREDIENT'],
                    ['slug' => Str::slug($categoryName), 'color' => '#00B14F', 'icon' => 'Package']
                );

                Ingredient::updateOrCreate(
                    [
                        'business_id' => $businessId,
                        'name'        => $name,
                    ],
                    [
                        'code'        => $code,
                        'category_id' => $cat->id,
                        'category'    => $cat->name,
                        'type'        => !empty($row['type']) ? strtoupper($row['type']) : 'RAW',
                        'unit_beli'   => $row['unit_beli'] ?? 'kg',
                        'unit_pakai'  => $row['unit_pakai'] ?? 'gram',
                        'konversi'    => (float)($row['konversi'] ?? 1000),
                        'harga'       => (float)($row['harga'] ?? 0),
                        'stok_min'    => (float)($row['minstok'] ?? 0),
                        'stok_awal'   => (float)($row['initial_stock'] ?? 0),
                        'notes'       => $row['notes'] ?? 'Imported from Excel',
                        'created_by'  => $user?->id,
                        'updated_by'  => $user?->id,
                    ]
                );

                $importedCount++;
            }
        });

        return response()->json([
            'message' => "Berhasil meng-import {$importedCount} master bahan dari file Excel.",
            'imported_count' => $importedCount,
        ]);
    }
}
