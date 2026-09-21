<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $businessId = $user?->business_id;

        // Auto-seed default categories for this business if none exist
        if ($businessId && Category::where('business_id', $businessId)->count() === 0) {
            $defaults = [
                ['name' => 'Perlengkapan', 'type' => 'INGREDIENT', 'color' => '#00B14F', 'icon' => 'Package', 'sort_order' => 1],
                ['name' => 'Bahan Baku', 'type' => 'INGREDIENT', 'color' => '#3B82F6', 'icon' => 'Layers', 'sort_order' => 2],
                ['name' => 'Bahan Olahan', 'type' => 'INGREDIENT', 'color' => '#8B5CF6', 'icon' => 'ChefHat', 'sort_order' => 3],
                ['name' => 'Dairy & Susu', 'type' => 'INGREDIENT', 'color' => '#F59E0B', 'icon' => 'Coffee', 'sort_order' => 4],
                ['name' => 'Bumbu & Sirup', 'type' => 'INGREDIENT', 'color' => '#EC4899', 'icon' => 'Sparkles', 'sort_order' => 5],
                ['name' => 'Packaging', 'type' => 'INGREDIENT', 'color' => '#06B6D4', 'icon' => 'ShoppingBag', 'sort_order' => 6],
            ];
            foreach ($defaults as $d) {
                Category::create(array_merge($d, [
                    'business_id' => $businessId,
                    'slug'        => Str::slug($d['name']),
                ]));
            }
        }

        $query = Category::orderBy('sort_order', 'asc')->orderBy('name', 'asc');

        if ($request->filled('type')) {
            $query->where(function ($q) use ($request) {
                $q->where('type', $request->type)
                  ->orWhere('type', 'GENERAL');
            });
        }

        $categories = $query->get();

        // Calculate count of ingredients and menus linked to each category
        $ingredientCounts = Ingredient::query()
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->selectRaw('category, category_id, COUNT(*) as total')
            ->groupBy('category', 'category_id')
            ->get();

        $categories->each(function ($cat) use ($ingredientCounts) {
            $matched = $ingredientCounts->filter(function ($row) use ($cat) {
                return $row->category_id == $cat->id || strcasecmp(trim($row->category ?? ''), trim($cat->name)) === 0;
            });
            $cat->ingredients_count = (int)$matched->sum('total');
        });

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'type'       => 'nullable|string|in:MENU,INGREDIENT,GENERAL',
            'color'      => 'nullable|string|max:20',
            'icon'       => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer',
        ]);

        $data['business_id'] = $request->user()?->business_id;
        $data['slug'] = Str::slug($data['name']);
        $data['type'] = $data['type'] ?? 'INGREDIENT';

        $category = Category::create($data);
        $category->ingredients_count = 0;
        return response()->json($category, 201);
    }

    public function show(Category $category)
    {
        return response()->json($category);
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'type'       => 'nullable|string|in:MENU,INGREDIENT,GENERAL',
            'color'      => 'nullable|string|max:20',
            'icon'       => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer',
        ]);

        $oldName = $category->name;

        if (!empty($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category->update($data);

        // If category name changed, update ingredients with matching category string
        if (!empty($data['name']) && $data['name'] !== $oldName) {
            Ingredient::where('business_id', $category->business_id)
                ->where(function ($q) use ($category, $oldName) {
                    $q->where('category_id', $category->id)
                      ->orWhere('category', $oldName);
                })
                ->update([
                    'category'    => $data['name'],
                    'category_id' => $category->id,
                ]);
        }

        return response()->json($category);
    }

    public function destroy(Category $category)
    {
        $itemsCount = Ingredient::where('business_id', $category->business_id)
            ->where(function ($q) use ($category) {
                $q->where('category_id', $category->id)
                  ->orWhere('category', $category->name);
            })->count();

        if ($itemsCount > 0) {
            return response()->json([
                'message' => "Kategori '{$category->name}' tidak dapat dihapus karena masih digunakan oleh {$itemsCount} item bahan/perlengkapan stok."
            ], 422);
        }

        $category->delete();
        return response()->json(['message' => 'Kategori berhasil dihapus']);
    }
}
