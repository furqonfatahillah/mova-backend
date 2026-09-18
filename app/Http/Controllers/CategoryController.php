<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::orderBy('sort_order', 'asc')->orderBy('name', 'asc');

        if ($request->filled('type')) {
            $query->where(function ($q) use ($request) {
                $q->where('type', $request->type)
                  ->orWhere('type', 'GENERAL');
            });
        }

        return response()->json($query->get());
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
        $data['type'] = $data['type'] ?? 'GENERAL';

        $category = Category::create($data);
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

        if (!empty($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category->update($data);
        return response()->json($category);
    }

    public function destroy(Category $category)
    {
        $category->delete();
        return response()->json(['message' => 'Kategori berhasil dihapus']);
    }
}
