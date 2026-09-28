<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\OutletMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        $isForPos = $request->boolean('for_pos') || $request->boolean('active_only') || $request->boolean('lite');

        if ($isForPos) {
            // ⚡ LIGHTWEIGHT POS MODE: Omit heavy audit relations (creator/updater), load only essential recipe and modifiers
            $relations = [
                'recipes' => fn($q) => $q->with('items.ingredient')->orderByDesc('version'),
                'bundleItems.bundledMenu' => fn($q) => $q->with(['recipes' => fn($rq) => $rq->with('items.ingredient')->orderByDesc('version')]),
                'bundleItems.ingredient',
                'modifierGroups.options.ingredient',
                'outletMenus',
            ];
        } else {
            $relations = [
                'creator',
                'updater',
                'recipes' => fn($q) => $q->with(['creator', 'updater', 'items.ingredient'])->orderByDesc('version'),
                'bundleItems.bundledMenu' => fn($q) => $q->with(['recipes' => fn($rq) => $rq->with('items.ingredient')->orderByDesc('version')]),
                'bundleItems.ingredient',
                'modifierGroups.options.ingredient',
                'outletMenus',
            ];
        }

        $query = Menu::with($relations)->orderBy('code');

        if ($isForPos || $request->filled('active')) {
            $query->where('active', true);
        }

        if ($request->filled('item_type') && in_array($request->item_type, ['RECIPE', 'DIRECT', 'SERVICE', 'BUNDLE'])) {
            $query->where('item_type', $request->item_type);
        }

        return response()->json($query->get());
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
                Rule::unique('menus', 'code')->where(function ($query) use ($businessId) {
                    return $businessId ? $query->where('business_id', $businessId) : $query;
                }),
            ],
            'barcode'     => 'nullable|string|max:50',
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'category'    => 'nullable|string|max:100',
            'item_type'   => 'nullable|string|in:RECIPE,DIRECT,SERVICE,BUNDLE',
            'track_stock' => 'nullable|boolean',
            'stock'       => 'nullable|numeric|min:0',
            'min_stock'   => 'nullable|numeric|min:0',
            'price'       => 'required|numeric|min:0',
            'cost_price'  => 'nullable|numeric|min:0',
            'unit'        => 'nullable|string|max:20',
            'active'      => 'nullable|boolean',
            'outlet_id'   => 'nullable|integer',
            'bundle_items'                  => 'nullable|array',
            'bundle_items.*.bundled_menu_id'=> 'nullable|exists:menus,id',
            'bundle_items.*.ingredient_id'  => 'nullable|exists:ingredients,id',
            'bundle_items.*.qty'            => 'required|numeric|min:0.001',
            'bundle_items.*.unit'           => 'nullable|string',
        ]);

        $data['item_type'] = $data['item_type'] ?? 'RECIPE';
        $data['track_stock'] = $data['track_stock'] ?? ($data['item_type'] !== 'SERVICE' && $data['item_type'] !== 'BUNDLE');
        $data['stock'] = (float)($data['stock'] ?? 0);
        $data['min_stock'] = (float)($data['min_stock'] ?? 0);
        $data['cost_price'] = (float)($data['cost_price'] ?? 0);
        $data['unit'] = $data['unit'] ?? ($data['item_type'] === 'DIRECT' ? 'pcs' : ($data['item_type'] === 'SERVICE' ? 'layanan' : ($data['item_type'] === 'BUNDLE' ? 'paket' : 'porsi')));
        $data['created_by'] = $request->user()?->id;

        if (!empty($data['category'])) {
            $cat = \App\Models\Category::firstOrCreate(
                ['business_id' => $businessId, 'name' => trim($data['category']), 'type' => 'MENU'],
                ['slug' => \Illuminate\Support\Str::slug(trim($data['category'])), 'color' => '#7C3AED', 'icon' => 'Utensils']
            );
            $data['category_id'] = $cat->id;
            $data['category'] = $cat->name;
        }

        $outletId = $data['outlet_id'] ?? null;
        $bundleItems = $data['bundle_items'] ?? null;
        unset($data['outlet_id'], $data['bundle_items']);

        $menu = Menu::create($data);

        if ($bundleItems && is_array($bundleItems)) {
            $this->syncBundleItems($menu, $bundleItems);
        }

        // Jika outlet_id diberikan dan tipe barang adalah DIRECT, set stok awal outlet
        if ($outletId && $menu->item_type === 'DIRECT') {
            OutletMenu::updateOrCreate(
                ['outlet_id' => $outletId, 'menu_id' => $menu->id],
                ['stock' => $menu->stock, 'min_stock' => $menu->min_stock]
            );
        }

        $storeRelations = [
            'creator',
            'updater',
            'bundleItems.bundledMenu' => fn($q) => $q->with(['recipes' => fn($rq) => $rq->with('items.ingredient')->orderByDesc('version')]),
            'bundleItems.ingredient',
            'modifierGroups.options.ingredient'
        ];
        if (Schema::hasTable('outlet_menus')) {
            $storeRelations[] = 'outletMenus';
        }
        $menu->load($storeRelations);
        return response()->json($menu, 201);
    }

    public function show(Menu $menu)
    {
        $showRelations = [
            'creator',
            'updater',
            'recipes' => fn($q) => $q->with(['creator', 'updater', 'items.ingredient'])->orderByDesc('version'),
            'bundleItems.bundledMenu' => fn($q) => $q->with(['recipes' => fn($rq) => $rq->with('items.ingredient')->orderByDesc('version')]),
            'bundleItems.ingredient',
            'modifierGroups.options.ingredient',
        ];
        if (Schema::hasTable('outlet_menus')) {
            $showRelations[] = 'outletMenus';
        }
        $menu->load($showRelations);
        return response()->json($menu);
    }

    public function update(Request $request, Menu $menu)
    {
        $businessId = $menu->business_id ?? $request->user()?->business_id;

        $data = $request->validate([
            'code' => [
                'sometimes', 'string', 'max:20',
                Rule::unique('menus', 'code')->where(function ($query) use ($businessId) {
                    return $businessId ? $query->where('business_id', $businessId) : $query;
                })->ignore($menu->id),
            ],
            'barcode'     => 'nullable|string|max:50',
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category'    => 'nullable|string|max:100',
            'item_type'   => 'nullable|string|in:RECIPE,DIRECT,SERVICE,BUNDLE',
            'track_stock' => 'nullable|boolean',
            'stock'       => 'nullable|numeric|min:0',
            'min_stock'   => 'nullable|numeric|min:0',
            'price'       => 'sometimes|numeric|min:0',
            'cost_price'  => 'nullable|numeric|min:0',
            'unit'        => 'nullable|string|max:20',
            'active'      => 'nullable|boolean',
            'outlet_id'   => 'nullable|integer',
            'bundle_items'                  => 'nullable|array',
            'bundle_items.*.bundled_menu_id'=> 'nullable|exists:menus,id',
            'bundle_items.*.ingredient_id'  => 'nullable|exists:ingredients,id',
            'bundle_items.*.qty'            => 'required|numeric|min:0.001',
            'bundle_items.*.unit'           => 'nullable|string',
        ]);

        if (!empty($data['category'])) {
            $cat = \App\Models\Category::firstOrCreate(
                ['business_id' => $businessId, 'name' => trim($data['category']), 'type' => 'MENU'],
                ['slug' => \Illuminate\Support\Str::slug(trim($data['category'])), 'color' => '#7C3AED', 'icon' => 'Utensils']
            );
            $data['category_id'] = $cat->id;
            $data['category'] = $cat->name;
        }

        $outletId = $data['outlet_id'] ?? null;
        $bundleItems = $data['bundle_items'] ?? null;
        unset($data['outlet_id'], $data['bundle_items']);

        $data['updated_by'] = $request->user()?->id;

        $costBefore = (float)($menu->cost_price ?? 0);

        $menu->update($data);

        // Record HPP change if cost_price changed on retail item
        if (array_key_exists('cost_price', $data) && abs($costBefore - (float)$menu->cost_price) > 0.001 && $menu->item_type === 'DIRECT') {
            try {
                \App\Services\MenuHppService::recordForDirectRestock(
                    $menu,
                    $costBefore,
                    (float)$menu->cost_price,
                    $outletId ? (int)$outletId : null,
                    $request->user()?->id,
                    "Pembaruan harga modal manual pada Edit Menu"
                );
            } catch (\Throwable $e) {}
        }

        if ($bundleItems !== null && is_array($bundleItems)) {
            $this->syncBundleItems($menu, $bundleItems);
        }

        // Jika stok diupdate untuk outlet tertentu
        if ($outletId && array_key_exists('stock', $data) && Schema::hasTable('outlet_menus')) {
            OutletMenu::updateOrCreate(
                ['outlet_id' => $outletId, 'menu_id' => $menu->id],
                ['stock' => (float)$data['stock'], 'min_stock' => (float)($data['min_stock'] ?? $menu->min_stock)]
            );
        }

        $updateRelations = [
            'creator',
            'updater',
            'bundleItems.bundledMenu' => fn($q) => $q->with(['recipes' => fn($rq) => $rq->with('items.ingredient')->orderByDesc('version')]),
            'bundleItems.ingredient',
            'recipes' => fn($q) => $q->with(['creator', 'updater', 'items.ingredient'])->orderByDesc('version')
        ];
        if (Schema::hasTable('outlet_menus')) {
            $updateRelations[] = 'outletMenus';
        }
        $menu->load($updateRelations);
        return response()->json($menu);
    }

    public function toggleActive(Request $request, Menu $menu)
    {
        $menu->active = !$menu->active;
        $menu->updated_by = $request->user()?->id;
        $menu->save();

        return response()->json([
            'message' => "Status produk '{$menu->name}' berhasil diubah menjadi " . ($menu->active ? 'Aktif' : 'Nonaktif'),
            'active'  => (bool)$menu->active,
            'menu'    => $menu
        ]);
    }

    private function syncBundleItems(Menu $menu, array $items)
    {
        $menu->bundleItems()->delete();
        foreach ($items as $it) {
            if (!empty($it['bundled_menu_id']) || !empty($it['ingredient_id'])) {
                \App\Models\BundleItem::create([
                    'menu_id'         => $menu->id,
                    'bundled_menu_id' => $it['bundled_menu_id'] ?? null,
                    'ingredient_id'   => $it['ingredient_id'] ?? null,
                    'qty'             => (float)($it['qty'] ?? 1),
                    'unit'            => $it['unit'] ?? null,
                ]);
            }
        }
    }

    public function destroy(Menu $menu)
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($menu) {
            \Illuminate\Support\Facades\DB::table('recipe_items')->where('menu_id', $menu->id)->delete();
            if (\Illuminate\Support\Facades\Schema::hasTable('menu_modifier_groups')) {
                \Illuminate\Support\Facades\DB::table('menu_modifier_groups')->where('menu_id', $menu->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('outlet_menus')) {
                \Illuminate\Support\Facades\DB::table('outlet_menus')->where('menu_id', $menu->id)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('bundle_items')) {
                \Illuminate\Support\Facades\DB::table('bundle_items')->where('menu_id', $menu->id)->orWhere('bundled_menu_id', $menu->id)->delete();
            }
            $menu->delete();
        });
        return response()->json(['message' => 'Deleted']);
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

        $query = Menu::whereIn('id', $ids);
        if ($businessId) {
            $query->where('business_id', $businessId);
        }
        $menus = $query->get();

        if ($menus->isEmpty()) {
            return response()->json(['message' => 'Tidak ada data menu yang ditemukan untuk dihapus.'], 404);
        }

        $deletedCount = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($menus, &$deletedCount) {
            $validIds = $menus->pluck('id')->toArray();

            \Illuminate\Support\Facades\DB::table('recipe_items')->whereIn('menu_id', $validIds)->delete();
            if (\Illuminate\Support\Facades\Schema::hasTable('menu_modifier_groups')) {
                \Illuminate\Support\Facades\DB::table('menu_modifier_groups')->whereIn('menu_id', $validIds)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('outlet_menus')) {
                \Illuminate\Support\Facades\DB::table('outlet_menus')->whereIn('menu_id', $validIds)->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('bundle_items')) {
                \Illuminate\Support\Facades\DB::table('bundle_items')->whereIn('menu_id', $validIds)->orWhereIn('bundled_menu_id', $validIds)->delete();
            }

            foreach ($menus as $m) {
                $m->delete();
                $deletedCount++;
            }
        });

        return response()->json([
            'message'       => "Berhasil menghapus {$deletedCount} data menu.",
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * Quick Restock produk barang retail langsung (DIRECT)
     */
    public function restock(Request $request, Menu $menu)
    {
        $data = $request->validate([
            'qty'        => 'required|numeric|min:0.01',
            'cost_price' => 'nullable|numeric|min:0',
            'total_cost' => 'nullable|numeric|min:0',
            'outlet_id'  => 'nullable|integer',
            'notes'      => 'nullable|string|max:255',
        ]);

        $outletId = $data['outlet_id'] ?? $request->user()?->outlet_id;
        $qty = (float)$data['qty'];

        $costBefore = (float)($menu->cost_price ?? 0);

        if ($data['cost_price'] !== null && (float)$data['cost_price'] > 0) {
            $menu->cost_price = (float)$data['cost_price'];
        } elseif (!empty($data['total_cost']) && (float)$data['total_cost'] > 0 && $qty > 0) {
            $menu->cost_price = round((float)$data['total_cost'] / $qty, 2);
        }

        // Tambah saldo master
        $menu->stock = (float)$menu->stock + $qty;
        $menu->save();

        // ⚡ Record HPP change if cost changed on restock
        if ($costBefore != (float)$menu->cost_price) {
            try {
                \App\Services\MenuHppService::recordForDirectRestock(
                    $menu,
                    $costBefore,
                    (float)$menu->cost_price,
                    $outletId ? (int)$outletId : null,
                    $request->user()?->id,
                    $data['notes'] ?? null
                );
            } catch (\Throwable $e) {}
        }

        // Jika outlet spesifik, update atau buat record OutletMenu
        if ($outletId && $outletId !== 'ALL' && Schema::hasTable('outlet_menus')) {
            $outletMenu = OutletMenu::firstOrNew(['outlet_id' => $outletId, 'menu_id' => $menu->id]);
            $outletMenu->stock = (float)($outletMenu->stock ?? 0) + $qty;
            $outletMenu->save();
        }

        if (Schema::hasTable('outlet_menus')) {
            $menu->load(['outletMenus']);
        }
        return response()->json([
            'message'       => "Stok produk '{$menu->name}' berhasil ditambah sebanyak {$qty} {$menu->unit}.",
            'menu'          => $menu,
            'current_stock' => $menu->current_stock,
        ]);
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

        $oldHpp = (float)$menu->calculateHpp();
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

        $newHpp = (float)$menu->calculateHpp();

        // ⚡ Record HPP history for new recipe version
        try {
            \App\Services\MenuHppService::recordForRecipeUpdate(
                $menu,
                $oldHpp,
                $newHpp,
                $request->user()?->id,
                "Pembaruan resep ke Versi {$nextVersion}"
            );
        } catch (\Throwable $e) {}

        $recipe->load(['creator', 'updater', 'items.ingredient']);
        return response()->json($recipe, 201);
    }

    /**
     * Get Menu HPP fluctuation history & composition breakdown (Weighted Moving Average)
     */
    public function hppHistory(Request $request, Menu $menu)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $outletId = $isOutletBounded ? (int)$user->outlet_id : ($request->outlet_id ? (int)$request->outlet_id : ($user?->outlet_id));

        $data = \App\Services\MenuHppService::getHppData(
            $menu,
            $outletId,
            $request->query('from'),
            $request->query('to'),
            (int)($request->query('limit') ?: 100)
        );

        return response()->json($data);
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

        \Illuminate\Support\Facades\DB::transaction(function () use ($items, $businessId, $user, &$importedCount) {
            foreach ($items as $idx => $row) {
                if (empty($row['name'])) continue;

                $name = trim($row['name']);
                $lowerName = strtolower($name);

                // Filter out accidental header / banner rows
                if (
                    str_starts_with($name, '===') ||
                    str_contains($lowerName, 'template import') ||
                    str_contains($lowerName, 'petunjuk') ||
                    str_contains($lowerName, 'daftar menu') ||
                    in_array($lowerName, ['kode menu', 'nama menu', 'nama menu*', 'kategori', 'kategori*', 'tipe item', 'harga jual'])
                ) {
                    continue;
                }

                $code = !empty($row['code']) ? trim($row['code']) : null;

                // If user didn't specify a code, auto-generate next safe code for this business without collision
                if (empty($code)) {
                    $seq = Menu::where('business_id', $businessId)->count() + 1;
                    do {
                        $candidateCode = 'MNU-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                        $exists = Menu::where('business_id', $businessId)->where('code', $candidateCode)->exists();
                        $seq++;
                    } while ($exists);
                    $code = $candidateCode;
                }

                $categoryName = !empty($row['category']) ? trim($row['category']) : 'Umum';
                $cat = \App\Models\Category::firstOrCreate(
                    ['business_id' => $businessId, 'name' => $categoryName, 'type' => 'MENU'],
                    ['slug' => \Illuminate\Support\Str::slug($categoryName), 'color' => '#7C3AED', 'icon' => 'Utensils']
                );

                Menu::updateOrCreate(
                    [
                        'business_id' => $businessId,
                        'code'        => $code,
                    ],
                    [
                        'name'         => $name,
                        'barcode'      => $row['barcode'] ?? null,
                        'category_id'  => $cat->id,
                        'category'     => $cat->name,
                        'item_type'    => !empty($row['item_type']) ? strtoupper($row['item_type']) : 'RECIPE',
                        'price'        => (float)($row['price'] ?? 0),
                        'cost_price'   => (float)($row['cost_price'] ?? 0),
                        'description'  => $row['description'] ?? null,
                        'is_available' => isset($row['is_available']) ? (bool)$row['is_available'] : true,
                        'created_by'   => $user?->id,
                        'updated_by'   => $user?->id,
                    ]
                );

                $importedCount++;
            }
        });

        return response()->json([
            'message' => "Berhasil meng-import {$importedCount} master menu dari file Excel.",
            'imported_count' => $importedCount,
        ]);
    }

    /**
     * Bulk Import Recipes & Grammage per Menu from Excel (Bill of Materials / BOM)
     */
    public function bulkImportRecipes(Request $request)
    {
        $request->validate([
            'items'   => 'required|array|min:1',
            'items.*' => 'required|array',
        ]);

        $user = $request->user();
        $businessId = $user?->business_id;
        $items = $request->input('items', []);

        $importedMenuCount = 0;
        $totalItemsCount = 0;

        \Illuminate\Support\Facades\DB::transaction(function () use ($items, $businessId, $user, &$importedMenuCount, &$totalItemsCount) {
            // Group rows by menu identifier (menu_code or menu_name)
            $groupedByMenu = [];
            foreach ($items as $row) {
                $menuName = trim($row['menu_name'] ?? $row['menu'] ?? $row['nama_menu'] ?? '');
                $menuCode = trim($row['menu_code'] ?? $row['kode_menu'] ?? '');
                if (empty($menuName) && empty($menuCode)) continue;

                $ingredientName = trim($row['ingredient_name'] ?? $row['bahan'] ?? $row['nama_bahan'] ?? $row['item_name'] ?? $row['nama_perlengkapan'] ?? '');
                if (empty($ingredientName)) continue;

                $key = !empty($menuCode) ? "code:{$menuCode}" : "name:" . strtolower($menuName);
                if (!isset($groupedByMenu[$key])) {
                    $groupedByMenu[$key] = [
                        'menu_code' => $menuCode,
                        'menu_name' => $menuName,
                        'recipe_items' => [],
                    ];
                }

                $groupedByMenu[$key]['recipe_items'][] = $row;
            }

            foreach ($groupedByMenu as $group) {
                $menuCode = $group['menu_code'];
                $menuName = $group['menu_name'];
                $recipeRows = $group['recipe_items'];

                if (empty($recipeRows)) continue;

                // 1. Find or create Menu
                $menu = null;
                if (!empty($menuCode)) {
                    $menu = Menu::where('business_id', $businessId)->where('code', $menuCode)->first();
                }
                if (!$menu && !empty($menuName)) {
                    $menu = Menu::where('business_id', $businessId)
                        ->where(\Illuminate\Support\Facades\DB::raw('LOWER(name)'), strtolower($menuName))
                        ->first();
                }

                if (!$menu) {
                    if (empty($menuCode)) {
                        $seq = Menu::where('business_id', $businessId)->count() + 1;
                        do {
                            $candidateCode = 'MNU-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                            $exists = Menu::where('business_id', $businessId)->where('code', $candidateCode)->exists();
                            $seq++;
                        } while ($exists);
                        $menuCode = $candidateCode;
                    }

                    $cat = \App\Models\Category::firstOrCreate(
                        ['business_id' => $businessId, 'name' => 'Minuman', 'type' => 'MENU'],
                        ['slug' => 'minuman', 'color' => '#7C3AED', 'icon' => 'Utensils']
                    );

                    $menu = Menu::create([
                        'business_id'  => $businessId,
                        'code'         => $menuCode,
                        'name'         => $menuName ?: "Menu {$menuCode}",
                        'category_id'  => $cat->id,
                        'category'     => $cat->name,
                        'item_type'    => 'RECIPE',
                        'price'        => 15000,
                        'cost_price'   => 0,
                        'is_available' => true,
                        'created_by'   => $user?->id,
                        'updated_by'   => $user?->id,
                    ]);
                } else {
                    if ($menu->item_type === 'DIRECT') {
                        $menu->item_type = 'RECIPE';
                        $menu->save();
                    }
                }

                $oldHpp = (float)$menu->calculateHpp();
                $nextVersion = ($menu->recipes()->max('version') ?? 0) + 1;

                $recipe = Recipe::create([
                    'menu_id'    => $menu->id,
                    'version'    => $nextVersion,
                    'date'       => $recipeRows[0]['date'] ?? now()->toDateString(),
                    'created_by' => $user?->id,
                    'updated_by' => $user?->id,
                ]);

                foreach ($recipeRows as $rRow) {
                    $ingName = trim($rRow['ingredient_name'] ?? $rRow['bahan'] ?? $rRow['nama_bahan'] ?? $rRow['item_name'] ?? $rRow['nama_perlengkapan'] ?? '');
                    $ingCode = trim($rRow['ingredient_code'] ?? $rRow['kode_bahan'] ?? '');
                    $qty = (float)($rRow['qty'] ?? $rRow['gramasi'] ?? $rRow['jumlah'] ?? 1);
                    $unit = trim($rRow['unit'] ?? $rRow['satuan'] ?? 'gram');
                    $waste = (float)($rRow['waste_std'] ?? $rRow['waste'] ?? $rRow['susut'] ?? 0);

                    // Match ingredient
                    $ingredient = null;
                    if (!empty($ingCode)) {
                        $ingredient = Ingredient::where('business_id', $businessId)->where('code', $ingCode)->first();
                    }
                    if (!$ingredient && !empty($ingName)) {
                        $ingredient = Ingredient::where('business_id', $businessId)
                            ->where(\Illuminate\Support\Facades\DB::raw('LOWER(name)'), strtolower($ingName))
                            ->first();
                    }

                    // Auto create ingredient if not exists
                    if (!$ingredient) {
                        $isPerl = in_array(strtolower($unit), ['pcs', 'lembar', 'slop', 'pack']) ||
                            preg_match('/(cup|sedotan|pipet|tissue|tisu|kantong|kresek|box|lid|sealer)/i', $ingName);

                        if (empty($ingCode)) {
                            $prefix = $isPerl ? 'PLK-' : 'BHN-';
                            $seq = Ingredient::where('business_id', $businessId)->count() + 1;
                            do {
                                $candidateCode = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
                                $exists = Ingredient::where('business_id', $businessId)->where('code', $candidateCode)->exists();
                                $seq++;
                            } while ($exists);
                            $ingCode = $candidateCode;
                        }

                        $ingCatName = $isPerl ? 'Perlengkapan' : 'BAHAN_BAKU';
                        $ingCat = \App\Models\Category::firstOrCreate(
                            ['business_id' => $businessId, 'name' => $ingCatName, 'type' => 'INGREDIENT'],
                            ['slug' => \Illuminate\Support\Str::slug($ingCatName), 'color' => '#00B14F', 'icon' => 'Package']
                        );

                        $unitBeli = $isPerl ? 'slop' : (in_array(strtolower($unit), ['ml', 'liter']) ? 'liter' : 'kg');
                        $unitPakai = $unit ?: ($isPerl ? 'pcs' : (in_array(strtolower($unit), ['ml', 'liter']) ? 'ml' : 'gram'));

                        $konversi = 1.0;
                        if ($unitBeli === 'kg' && $unitPakai === 'gram') $konversi = 1000.0;
                        elseif ($unitBeli === 'liter' && $unitPakai === 'ml') $konversi = 1000.0;
                        elseif ($unitBeli === 'slop' && $unitPakai === 'pcs') $konversi = 50.0;
                        elseif ($unitBeli === 'pack') $konversi = 100.0;

                        $ingredient = Ingredient::create([
                            'business_id'   => $businessId,
                            'code'          => $ingCode,
                            'name'          => $ingName,
                            'category_id'   => $ingCat->id,
                            'category'      => $ingCat->name,
                            'type'          => 'RAW',
                            'unit_beli'     => $unitBeli,
                            'unit_pakai'    => $unitPakai,
                            'konversi'      => $konversi,
                            'harga'         => 0,
                            'stok_min'      => 0,
                            'stok_awal'     => 0,
                            'created_by'    => $user?->id,
                            'updated_by'    => $user?->id,
                        ]);
                    }

                    RecipeItem::create([
                        'recipe_id'     => $recipe->id,
                        'ingredient_id' => $ingredient->id,
                        'qty'           => $qty,
                        'unit'          => $unit ?: $ingredient->unit_pakai,
                        'waste_std'     => $waste,
                    ]);

                    $totalItemsCount++;
                }

                $newHpp = (float)$menu->calculateHpp();

                try {
                    \App\Services\MenuHppService::recordForRecipeUpdate(
                        $menu,
                        $oldHpp,
                        $newHpp,
                        $user?->id,
                        "Import resep Excel ke Versi {$nextVersion}"
                    );
                } catch (\Throwable $e) {}

                $importedMenuCount++;
            }
        });

        return response()->json([
            'message'             => "Berhasil meng-import resep untuk {$importedMenuCount} menu ({$totalItemsCount} rincian bahan/gramasi terpasang).",
            'imported_menu_count' => $importedMenuCount,
            'total_items_count'   => $totalItemsCount,
        ]);
    }
}
