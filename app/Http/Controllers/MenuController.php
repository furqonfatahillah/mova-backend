<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\Recipe;
use App\Models\RecipeItem;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function index()
    {
        return response()->json(
            Menu::with([
                'creator',
                'updater',
                'recipes' => fn($q) => $q->with(['creator', 'updater', 'items.ingredient'])->orderByDesc('version'),
                'modifierGroups.options.ingredient',
            ])
            ->orderBy('code')
            ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'     => 'required|string|max:20|unique:menus',
            'name'     => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'price'    => 'required|numeric|min:0',
            'active'   => 'nullable|boolean',
        ]);

        $data['created_by'] = $request->user()?->id;

        $menu = Menu::create($data);
        $menu->load(['creator', 'updater', 'modifierGroups.options.ingredient']);
        return response()->json($menu, 201);
    }

    public function show(Menu $menu)
    {
        $menu->load([
            'creator',
            'updater',
            'recipes' => fn($q) => $q->with(['creator', 'updater', 'items.ingredient'])->orderByDesc('version'),
            'modifierGroups.options.ingredient',
        ]);
        return response()->json($menu);
    }

    public function update(Request $request, Menu $menu)
    {
        $data = $request->validate([
            'code'     => 'sometimes|string|max:20|unique:menus,code,' . $menu->id,
            'name'     => 'sometimes|string|max:255',
            'category' => 'nullable|string|max:100',
            'price'    => 'sometimes|numeric|min:0',
            'active'   => 'nullable|boolean',
        ]);

        $data['updated_by'] = $request->user()?->id;

        $menu->update($data);
        $menu->load(['creator', 'updater']);
        return response()->json($menu);
    }

    public function destroy(Menu $menu)
    {
        $menu->delete();
        return response()->json(['message' => 'Deleted']);
    }

    /** Save a new recipe version for a menu */
    public function storeRecipe(Request $request, Menu $menu)
    {
        $data = $request->validate([
            'date'         => 'required|date',
            'items'        => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|exists:ingredients,id',
            'items.*.qty'           => 'required|numeric|min:0.001',
            'items.*.unit'          => 'required|string|max:20',
            'items.*.waste_std'     => 'nullable|numeric|min:0|max:100',
        ]);

        $nextVersion = ($menu->recipes()->max('version') ?? 0) + 1;

        $recipe = Recipe::create([
            'menu_id'    => $menu->id,
            'version'    => $nextVersion,
            'date'       => $data['date'],
            'created_by' => $request->user()?->id,
        ]);

        foreach ($data['items'] as $item) {
            RecipeItem::create([
                'recipe_id'     => $recipe->id,
                'ingredient_id' => $item['ingredient_id'],
                'qty'           => $item['qty'],
                'unit'          => $item['unit'],
                'waste_std'     => $item['waste_std'] ?? 0,
            ]);
        }

        $recipe->load(['creator', 'updater', 'items.ingredient']);
        return response()->json($recipe, 201);
    }
}
