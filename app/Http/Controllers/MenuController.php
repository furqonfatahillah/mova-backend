<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\OutletMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class MenuController extends Controller
{
    public function index(Request $request)
    {
        $relations = [
            'creator',
            'updater',
            'recipes' => fn($q) => $q->with(['creator', 'updater', 'items.ingredient'])->orderByDesc('version'),
            'bundleItems.bundledMenu' => fn($q) => $q->with(['recipes' => fn($rq) => $rq->with('items.ingredient')->orderByDesc('version')]),
            'bundleItems.ingredient',
            'modifierGroups.options.ingredient',
            'outletMenus',
        ];

        $query = Menu::with($relations)->orderBy('code');

        if ($request->filled('item_type') && in_array($request->item_type, ['RECIPE', 'DIRECT', 'SERVICE', 'BUNDLE'])) {
            $query->where('item_type', $request->item_type);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code'        => 'required|string|max:20|unique:menus',
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
        $data = $request->validate([
            'code'        => 'sometimes|string|max:20|unique:menus,code,' . $menu->id,
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

        $outletId = $data['outlet_id'] ?? null;
        $bundleItems = $data['bundle_items'] ?? null;
        unset($data['outlet_id'], $data['bundle_items']);

        $data['updated_by'] = $request->user()?->id;

        $menu->update($data);

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
        $menu->delete();
        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Quick Restock produk barang retail langsung (DIRECT)
     */
    public function restock(Request $request, Menu $menu)
    {
        $data = $request->validate([
            'qty'        => 'required|numeric|min:0.01',
            'cost_price' => 'nullable|numeric|min:0',
            'outlet_id'  => 'nullable|integer',
            'notes'      => 'nullable|string|max:255',
        ]);

        $outletId = $data['outlet_id'] ?? $request->user()?->outlet_id;
        $qty = (float)$data['qty'];

        if ($data['cost_price'] !== null && $data['cost_price'] > 0) {
            $menu->cost_price = (float)$data['cost_price'];
        }

        // Tambah saldo master
        $menu->stock = (float)$menu->stock + $qty;
        $menu->save();

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
