<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $query = Ingredient::with(['creator', 'updater', 'prepRecipe.items.ingredient'])
            ->orderBy('code');

        if ($request->filled('type') && in_array($request->type, ['RAW', 'SEMI_FINISHED'])) {
            $query->where('type', $request->type);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'       => 'required|string|max:20|unique:ingredients',
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
        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient']);
        return response()->json($ingredient, 201);
    }

    public function show(Ingredient $ingredient)
    {
        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient']);
        return response()->json($ingredient);
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $data = $request->validate([
            'code'       => 'sometimes|string|max:20|unique:ingredients,code,' . $ingredient->id,
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
        $ingredient->load(['creator', 'updater', 'prepRecipe.items.ingredient']);
        return response()->json($ingredient);
    }

    public function destroy(Ingredient $ingredient)
    {
        $ingredient->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
