<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $query = Ingredient::with(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements'])
            ->orderBy('code');

        if ($request->filled('type') && in_array($request->type, ['RAW', 'SEMI_FINISHED'])) {
            $query->where('type', $request->type);
        }

        $ingredients = $query->get();

        // ⚡ Explicitly append stock attributes (removed from default $appends for performance)
        $ingredients->each->append(['current_stock', 'current_stok_min', 'outlet_stocks']);

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
            'name'       => 'required|string|max:255',
            'category'   => 'required|string|max:100',
            'type'       => 'nullable|string|in:RAW,SEMI_FINISHED',
            'unit_beli'  => 'required|string|max:20',
            'unit_pakai' => 'required|string|max:20',
            'konversi'   => 'required|numeric|min:0.001',
            'harga'      => 'required|numeric|min:0',
            'stok_awal'  => 'nullable|numeric|min:0',
            'stok_min'   => 'nullable|numeric|min:0',
            'tolerance'  => 'nullable|numeric|min:0|max:100',
            'yield_qty'  => 'nullable|numeric|min:0',
            'yield_unit' => 'nullable|string|max:20',
            'active'     => 'nullable|boolean',
        ]);

        $data['type'] = $data['type'] ?? 'RAW';
        $data['created_by'] = $request->user()?->id;

        $ingredient = Ingredient::create($data);
        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements']);
        $ingredient->append(['current_stock', 'current_stok_min', 'outlet_stocks']);
        return response()->json($ingredient, 201);
    }

    public function show(Ingredient $ingredient)
    {
        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements']);
        $ingredient->append(['current_stock', 'current_stok_min', 'outlet_stocks']);
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
            'name'       => 'sometimes|string|max:255',
            'category'   => 'sometimes|string|max:100',
            'type'       => 'nullable|string|in:RAW,SEMI_FINISHED',
            'unit_beli'  => 'sometimes|string|max:20',
            'unit_pakai' => 'sometimes|string|max:20',
            'konversi'   => 'sometimes|numeric|min:0.001',
            'harga'      => 'sometimes|numeric|min:0',
            'stok_awal'  => 'nullable|numeric|min:0',
            'stok_min'   => 'nullable|numeric|min:0',
            'tolerance'  => 'nullable|numeric|min:0|max:100',
            'yield_qty'  => 'nullable|numeric|min:0',
            'yield_unit' => 'nullable|string|max:20',
            'active'     => 'nullable|boolean',
        ]);

        $data['updated_by'] = $request->user()?->id;

        $ingredient->update($data);
        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient', 'outletIngredients', 'movements']);
        $ingredient->append(['current_stock', 'current_stok_min', 'outlet_stocks']);
        return response()->json($ingredient);
    }

    public function destroy(Ingredient $ingredient)
    {
        $ingredient->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
