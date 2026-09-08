<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModifierController extends Controller
{
    /**
     * List all modifier groups for current business
     */
    public function index(Request $request)
    {
        $query = ModifierGroup::with(['options.ingredient', 'menus'])
            ->orderBy('name');

        if ($request->filled('menu_id')) {
            $query->whereHas('menus', function ($q) use ($request) {
                $q->where('menus.id', $request->menu_id);
            });
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhereHas('options', fn($qo) => $qo->where('name', 'like', "%{$s}%"));
            });
        }

        return response()->json($query->get());
    }

    /**
     * Create a new modifier group with its options
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:255',
            'selection_type' => 'required|in:SINGLE,MULTIPLE',
            'is_required'    => 'nullable|boolean',
            'min_selection'  => 'nullable|integer|min:0',
            'max_selection'  => 'nullable|integer|min:1',
            'menu_ids'       => 'nullable|array',
            'menu_ids.*'     => 'exists:menus,id',
            'options'        => 'required|array|min:1',
            'options.*.name'          => 'required|string|max:255',
            'options.*.price'         => 'nullable|numeric|min:0',
            'options.*.ingredient_id' => 'nullable|exists:ingredients,id',
            'options.*.qty'           => 'nullable|numeric|min:0',
            'options.*.unit'          => 'nullable|string|max:20',
            'options.*.sort_order'    => 'nullable|integer',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $group = ModifierGroup::create([
                'name'           => $data['name'],
                'selection_type' => $data['selection_type'],
                'is_required'    => $data['is_required'] ?? false,
                'min_selection'  => $data['min_selection'] ?? 0,
                'max_selection'  => $data['max_selection'] ?? null,
                'created_by'     => $request->user()?->id,
            ]);

            foreach ($data['options'] as $idx => $opt) {
                ModifierOption::create([
                    'modifier_group_id' => $group->id,
                    'name'              => $opt['name'],
                    'price'             => $opt['price'] ?? 0,
                    'ingredient_id'     => $opt['ingredient_id'] ?? null,
                    'qty'               => $opt['qty'] ?? 0,
                    'unit'              => $opt['unit'] ?? null,
                    'sort_order'        => $opt['sort_order'] ?? $idx,
                ]);
            }

            if (!empty($data['menu_ids'])) {
                $group->menus()->sync($data['menu_ids']);
            }

            $group->load(['options.ingredient', 'menus', 'creator', 'updater']);
            return response()->json($group, 201);
        });
    }

    /**
     * Show single modifier group
     */
    public function show(ModifierGroup $modifierGroup)
    {
        $modifierGroup->load(['options.ingredient', 'menus', 'creator', 'updater']);
        return response()->json($modifierGroup);
    }

    /**
     * Update modifier group and its options
     */
    public function update(Request $request, ModifierGroup $modifierGroup)
    {
        $data = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'selection_type' => 'sometimes|in:SINGLE,MULTIPLE',
            'is_required'    => 'nullable|boolean',
            'min_selection'  => 'nullable|integer|min:0',
            'max_selection'  => 'nullable|integer|min:1',
            'menu_ids'       => 'nullable|array',
            'menu_ids.*'     => 'exists:menus,id',
            'options'        => 'sometimes|array|min:1',
            'options.*.name'          => 'required|string|max:255',
            'options.*.price'         => 'nullable|numeric|min:0',
            'options.*.ingredient_id' => 'nullable|exists:ingredients,id',
            'options.*.qty'           => 'nullable|numeric|min:0',
            'options.*.unit'          => 'nullable|string|max:20',
            'options.*.sort_order'    => 'nullable|integer',
        ]);

        return DB::transaction(function () use ($data, $request, $modifierGroup) {
            $modifierGroup->update([
                'name'           => $data['name'] ?? $modifierGroup->name,
                'selection_type' => $data['selection_type'] ?? $modifierGroup->selection_type,
                'is_required'    => array_key_exists('is_required', $data) ? $data['is_required'] : $modifierGroup->is_required,
                'min_selection'  => array_key_exists('min_selection', $data) ? $data['min_selection'] : $modifierGroup->min_selection,
                'max_selection'  => array_key_exists('max_selection', $data) ? $data['max_selection'] : $modifierGroup->max_selection,
                'updated_by'     => $request->user()?->id,
            ]);

            if (isset($data['options'])) {
                // Delete old options and recreate
                $modifierGroup->options()->delete();
                foreach ($data['options'] as $idx => $opt) {
                    ModifierOption::create([
                        'modifier_group_id' => $modifierGroup->id,
                        'name'              => $opt['name'],
                        'price'             => $opt['price'] ?? 0,
                        'ingredient_id'     => $opt['ingredient_id'] ?? null,
                        'qty'               => $opt['qty'] ?? 0,
                        'unit'              => $opt['unit'] ?? null,
                        'sort_order'        => $opt['sort_order'] ?? $idx,
                    ]);
                }
            }

            if (isset($data['menu_ids'])) {
                $modifierGroup->menus()->sync($data['menu_ids']);
            }

            $modifierGroup->load(['options.ingredient', 'menus', 'creator', 'updater']);
            return response()->json($modifierGroup);
        });
    }

    /**
     * Delete modifier group
     */
    public function destroy(ModifierGroup $modifierGroup)
    {
        $modifierGroup->delete();
        return response()->json(['message' => 'Kelompok modifier berhasil dihapus']);
    }

    /**
     * Assign modifier groups to a specific menu
     */
    public function assignToMenu(Request $request, Menu $menu)
    {
        $data = $request->validate([
            'modifier_group_ids'   => 'present|array',
            'modifier_group_ids.*' => 'exists:modifier_groups,id',
        ]);

        $menu->modifierGroups()->sync($data['modifier_group_ids']);
        $menu->load(['modifierGroups.options.ingredient']);

        return response()->json($menu);
    }
}
