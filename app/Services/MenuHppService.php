<?php

namespace App\Services;

use App\Models\Menu;
use App\Models\Ingredient;
use App\Models\RecipeItem;
use App\Models\MenuHppHistory;
use App\Models\BundleItem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class MenuHppService
{
    /**
     * Record HPP changes for all menus affected by an ingredient's moving average cost change.
     */
    public static function recordForIngredientCostChange(
        Ingredient $ingredient,
        float $costBefore,
        float $costAfter,
        ?int $outletId = null,
        string $triggerType = 'PURCHASE_RESTOCK',
        ?int $userId = null,
        ?string $customNotes = null
    ): void {
        if (!Schema::hasTable('menu_hpp_histories')) {
            return;
        }

        if (abs($costAfter - $costBefore) < 0.0001) {
            return;
        }

        $userId = $userId ?: auth()->id();
        $today = now()->toDateString();
        $unitPakai = $ingredient->unit_pakai ?: 'satuan';
        $costDelta = $costAfter - $costBefore;

        // 1. Find all active recipe items directly using this ingredient
        $recipeItems = RecipeItem::where('ingredient_id', $ingredient->id)
            ->with(['recipe.menu.recipes'])
            ->get();

        $processedMenuIds = [];

        foreach ($recipeItems as $ri) {
            $recipe = $ri->recipe;
            if (!$recipe || !$recipe->menu) {
                continue;
            }

            $menu = $recipe->menu;
            if (in_array($menu->id, $processedMenuIds)) {
                continue;
            }

            // Check if this recipe is the active (latest) version
            $latestRecipe = $menu->recipes->sortByDesc('version')->first();
            if (!$latestRecipe || $latestRecipe->id !== $recipe->id) {
                continue;
            }

            $processedMenuIds[] = $menu->id;

            $portionQty = (float)$ri->qty;
            $portionImpact = round($portionQty * $costDelta, 2);

            // Current dynamic HPP (which reflects the newly updated ingredient cost)
            $hppAfter = (float)$menu->calculateHpp(null, $outletId);
            $hppBefore = max(0.0, round($hppAfter - $portionImpact, 2));

            $diff = round($hppAfter - $hppBefore, 2);
            $pctChange = $hppBefore > 0 ? round(($diff / $hppBefore) * 100, 2) : 0.0;
            $sellingPrice = (float)($menu->price ?? 0);

            $marginBefore = $sellingPrice > 0 ? round((($sellingPrice - $hppBefore) / $sellingPrice) * 100, 2) : 0.0;
            $marginAfter  = $sellingPrice > 0 ? round((($sellingPrice - $hppAfter) / $sellingPrice) * 100, 2) : 0.0;

            $direction = $diff >= 0 ? 'naik' : 'turun';
            $absDiff = abs($diff);
            $diffFmt = 'Rp ' . number_format($absDiff, 0, ',', '.');
            $cbFmt   = 'Rp ' . number_format($costBefore, 0, ',', '.');
            $caFmt   = 'Rp ' . number_format($costAfter, 0, ',', '.');

            $defaultNote = "Harga rata-rata (Moving Avg) {$ingredient->name} {$direction} dari {$cbFmt} ke {$caFmt}/{$unitPakai}. HPP porsi {$direction} {$diffFmt}.";
            $note = $customNotes ?: $defaultNote;

            MenuHppHistory::create([
                'business_id'            => $menu->business_id,
                'menu_id'                => $menu->id,
                'outlet_id'              => $outletId,
                'date'                   => $today,
                'hpp_before'             => $hppBefore,
                'hpp_after'              => $hppAfter,
                'diff'                   => $diff,
                'percentage_change'      => $pctChange,
                'selling_price'          => $sellingPrice,
                'margin_before_pct'      => $marginBefore,
                'margin_after_pct'       => $marginAfter,
                'trigger_type'           => $triggerType,
                'ingredient_id'          => $ingredient->id,
                'ingredient_name'        => $ingredient->name,
                'ingredient_cost_before' => $costBefore,
                'ingredient_cost_after'  => $costAfter,
                'portion_qty'            => $portionQty,
                'portion_unit'           => $ri->unit ?: $unitPakai,
                'portion_cost_impact'    => $portionImpact,
                'notes'                  => $note,
                'user_id'                => $userId,
            ]);

            // If this menu is included in any bundles, propagate to the bundle as well
            self::propagateBundleHppChange($menu, $portionImpact, $outletId, $triggerType, $ingredient, $userId);
        }
    }

    /**
     * Propagate HPP changes to any parent Bundle menus.
     */
    protected static function propagateBundleHppChange(
        Menu $childMenu,
        float $childHppDelta,
        ?int $outletId,
        string $triggerType,
        Ingredient $ingredient,
        ?int $userId
    ): void {
        $bundleItems = BundleItem::where('bundled_menu_id', $childMenu->id)
            ->with(['menu'])
            ->get();

        foreach ($bundleItems as $bi) {
            $bundleMenu = $bi->menu;
            if (!$bundleMenu) continue;

            $bundlePortion = (float)($bi->qty ?? 1);
            $bundleImpact = round($bundlePortion * $childHppDelta, 2);

            $bundleHppAfter = (float)$bundleMenu->calculateHpp(null, $outletId);
            $bundleHppBefore = max(0.0, round($bundleHppAfter - $bundleImpact, 2));
            $diff = round($bundleHppAfter - $bundleHppBefore, 2);
            $pctChange = $bundleHppBefore > 0 ? round(($diff / $bundleHppBefore) * 100, 2) : 0.0;
            $sellingPrice = (float)($bundleMenu->price ?? 0);
            $marginBefore = $sellingPrice > 0 ? round((($sellingPrice - $bundleHppBefore) / $sellingPrice) * 100, 2) : 0.0;
            $marginAfter  = $sellingPrice > 0 ? round((($sellingPrice - $bundleHppAfter) / $sellingPrice) * 100, 2) : 0.0;

            MenuHppHistory::create([
                'business_id'            => $bundleMenu->business_id,
                'menu_id'                => $bundleMenu->id,
                'outlet_id'              => $outletId,
                'date'                   => now()->toDateString(),
                'hpp_before'             => $bundleHppBefore,
                'hpp_after'              => $bundleHppAfter,
                'diff'                   => $diff,
                'percentage_change'      => $pctChange,
                'selling_price'          => $sellingPrice,
                'margin_before_pct'      => $marginBefore,
                'margin_after_pct'       => $marginAfter,
                'trigger_type'           => $triggerType,
                'ingredient_id'          => $ingredient->id,
                'ingredient_name'        => $ingredient->name,
                'portion_qty'            => $bundlePortion,
                'portion_unit'           => 'paket',
                'portion_cost_impact'    => $bundleImpact,
                'notes'                  => "Perubahan HPP paket karena perubahan biaya bahan pada item {$childMenu->name}.",
                'user_id'                => $userId,
            ]);
        }
    }

    /**
     * Record HPP change when a recipe version is created or updated.
     */
    public static function recordForRecipeUpdate(
        Menu $menu,
        float $oldHpp,
        float $newHpp,
        ?int $userId = null,
        ?string $notes = null
    ): ?MenuHppHistory {
        if (!Schema::hasTable('menu_hpp_histories')) return null;

        $userId = $userId ?: auth()->id();
        $diff = round($newHpp - $oldHpp, 2);
        $pctChange = $oldHpp > 0 ? round(($diff / $oldHpp) * 100, 2) : 0.0;
        $sellingPrice = (float)($menu->price ?? 0);
        $marginBefore = $sellingPrice > 0 ? round((($sellingPrice - $oldHpp) / $sellingPrice) * 100, 2) : 0.0;
        $marginAfter  = $sellingPrice > 0 ? round((($sellingPrice - $newHpp) / $sellingPrice) * 100, 2) : 0.0;

        $note = $notes ?: "Pembaruan versi resep BOM menu.";

        return MenuHppHistory::create([
            'business_id'            => $menu->business_id,
            'menu_id'                => $menu->id,
            'outlet_id'              => null, // Master Recipe Level
            'date'                   => now()->toDateString(),
            'hpp_before'             => $oldHpp,
            'hpp_after'              => $newHpp,
            'diff'                   => $diff,
            'percentage_change'      => $pctChange,
            'selling_price'          => $sellingPrice,
            'margin_before_pct'      => $marginBefore,
            'margin_after_pct'       => $marginAfter,
            'trigger_type'           => 'RECIPE_UPDATE',
            'notes'                  => $note,
            'user_id'                => $userId,
        ]);
    }

    /**
     * Record HPP change when a retail product (DIRECT) is restocked with a new cost price.
     */
    public static function recordForDirectRestock(
        Menu $menu,
        float $costBefore,
        float $costAfter,
        ?int $outletId = null,
        ?int $userId = null,
        ?string $notes = null
    ): ?MenuHppHistory {
        if (!Schema::hasTable('menu_hpp_histories')) return null;

        $userId = $userId ?: auth()->id();
        $diff = round($costAfter - $costBefore, 2);
        $pctChange = $costBefore > 0 ? round(($diff / $costBefore) * 100, 2) : 0.0;
        $sellingPrice = (float)($menu->price ?? 0);
        $marginBefore = $sellingPrice > 0 ? round((($sellingPrice - $costBefore) / $sellingPrice) * 100, 2) : 0.0;
        $marginAfter  = $sellingPrice > 0 ? round((($sellingPrice - $costAfter) / $sellingPrice) * 100, 2) : 0.0;

        $note = $notes ?: "Restock produk retail langsung.";

        return MenuHppHistory::create([
            'business_id'            => $menu->business_id,
            'menu_id'                => $menu->id,
            'outlet_id'              => $outletId,
            'date'                   => now()->toDateString(),
            'hpp_before'             => $costBefore,
            'hpp_after'              => $costAfter,
            'diff'                   => $diff,
            'percentage_change'      => $pctChange,
            'selling_price'          => $sellingPrice,
            'margin_before_pct'      => $marginBefore,
            'margin_after_pct'       => $marginAfter,
            'trigger_type'           => 'DIRECT_RESTOCK',
            'notes'                  => $note,
            'user_id'                => $userId,
        ]);
    }

    /**
     * Record manual cost price change.
     */
    public static function recordForManualEdit(
        Menu $menu,
        float $costBefore,
        float $costAfter,
        ?int $userId = null,
        ?string $notes = null
    ): ?MenuHppHistory {
        if (!Schema::hasTable('menu_hpp_histories')) return null;
        if (abs($costAfter - $costBefore) < 0.01) return null;

        $userId = $userId ?: auth()->id();
        $diff = round($costAfter - $costBefore, 2);
        $pctChange = $costBefore > 0 ? round(($diff / $costBefore) * 100, 2) : 0.0;
        $sellingPrice = (float)($menu->price ?? 0);
        $marginBefore = $sellingPrice > 0 ? round((($sellingPrice - $costBefore) / $sellingPrice) * 100, 2) : 0.0;
        $marginAfter  = $sellingPrice > 0 ? round((($sellingPrice - $costAfter) / $sellingPrice) * 100, 2) : 0.0;

        return MenuHppHistory::create([
            'business_id'            => $menu->business_id,
            'menu_id'                => $menu->id,
            'outlet_id'              => null,
            'date'                   => now()->toDateString(),
            'hpp_before'             => $costBefore,
            'hpp_after'              => $costAfter,
            'diff'                   => $diff,
            'percentage_change'      => $pctChange,
            'selling_price'          => $sellingPrice,
            'margin_before_pct'      => $marginBefore,
            'margin_after_pct'       => $marginAfter,
            'trigger_type'           => 'MANUAL_EDIT',
            'notes'                  => $notes ?: "Penyesuaian manual estimasi harga modal menu.",
            'user_id'                => $userId,
        ]);
    }

    /**
     * Get HPP history & current composition breakdown for a menu.
     */
    public static function getHppData(
        Menu $menu,
        ?int $outletId = null,
        ?string $from = null,
        ?string $to = null,
        int $limit = 100
    ): array {
        $currentHpp = (float)$menu->calculateHpp(null, $outletId);
        $sellingPrice = (float)($menu->price ?? 0);
        $currentMargin = $sellingPrice > 0 ? round((($sellingPrice - $currentHpp) / $sellingPrice) * 100, 2) : 0.0;

        // Current ingredients breakdown with moving avg cost and contribution
        $ingredientsBreakdown = [];
        $activeRecipe = $menu->recipes()->orderByDesc('version')->first();

        if ($menu->is_bundle) {
            foreach ($menu->bundleItems as $bi) {
                if ($bi->bundledMenu) {
                    $bHpp = (float)$bi->bundledMenu->calculateHpp(null, $outletId);
                    $subtotal = round((float)$bi->qty * $bHpp, 2);
                    $share = $currentHpp > 0 ? round(($subtotal / $currentHpp) * 100, 1) : 0;
                    $ingredientsBreakdown[] = [
                        'type'            => 'MENU',
                        'item_id'         => $bi->bundledMenu->id,
                        'name'            => $bi->bundledMenu->name,
                        'qty'             => (float)$bi->qty,
                        'unit'            => $bi->unit ?: 'porsi',
                        'cost_per_unit'   => $bHpp,
                        'subtotal'        => $subtotal,
                        'contribution_pct'=> $share,
                    ];
                } elseif ($bi->ingredient) {
                    $ing = $bi->ingredient;
                    $costPerPakai = (float)$ing->costPerPakaiForOutlet($outletId);
                    $subtotal = round((float)$bi->qty * $costPerPakai, 2);
                    $share = $currentHpp > 0 ? round(($subtotal / $currentHpp) * 100, 1) : 0;
                    $ingredientsBreakdown[] = [
                        'type'            => 'INGREDIENT',
                        'item_id'         => $ing->id,
                        'name'            => $ing->name,
                        'qty'             => (float)$bi->qty,
                        'unit'            => $bi->unit ?: ($ing->unit_pakai ?: 'satuan'),
                        'cost_per_unit'   => round($costPerPakai, 2),
                        'subtotal'        => $subtotal,
                        'contribution_pct'=> $share,
                    ];
                }
            }
        } elseif ($activeRecipe && $menu->item_type === 'RECIPE') {
            foreach ($activeRecipe->items as $item) {
                $ing = $item->ingredient;
                if (!$ing) continue;
                $costPerPakai = (float)$ing->costPerPakaiForOutlet($outletId);
                $subtotal = round((float)$item->qty * $costPerPakai, 2);
                $share = $currentHpp > 0 ? round(($subtotal / $currentHpp) * 100, 1) : 0;
                $ingredientsBreakdown[] = [
                    'type'            => 'INGREDIENT',
                    'item_id'         => $ing->id,
                    'name'            => $ing->name,
                    'category'        => $ing->category,
                    'qty'             => (float)$item->qty,
                    'unit'            => $item->unit ?: ($ing->unit_pakai ?: 'satuan'),
                    'cost_per_unit'   => round($costPerPakai, 2),
                    'subtotal'        => $subtotal,
                    'contribution_pct'=> $share,
                ];
            }
        } elseif ($menu->item_type === 'DIRECT') {
            $ingredientsBreakdown[] = [
                'type'            => 'DIRECT_PRODUCT',
                'item_id'         => $menu->id,
                'name'            => $menu->name,
                'qty'             => 1,
                'unit'            => $menu->unit ?: 'pcs',
                'cost_per_unit'   => $currentHpp,
                'subtotal'        => $currentHpp,
                'contribution_pct'=> 100.0,
            ];
        }

        // Query history
        $historyQuery = MenuHppHistory::where('menu_id', $menu->id)
            ->with(['outlet', 'ingredient', 'user'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $historyQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', (int)$outletId)
                  ->orWhereNull('outlet_id');
            });
        }

        if ($from) {
            $historyQuery->where('date', '>=', $from);
        }
        if ($to) {
            $historyQuery->where('date', '<=', $to);
        }

        $historyList = $historyQuery->limit($limit)->get();

        // If history is completely empty, generate an initial BASELINE record so users have a starting point
        if ($historyList->isEmpty()) {
            $baseline = self::createInitialBaseline($menu, $currentHpp, $outletId);
            if ($baseline) {
                $historyList = collect([$baseline->fresh(['outlet', 'ingredient', 'user'])]);
            }
        }

        // Stats calculation
        $allHppValues = $historyList->pluck('hpp_after')->merge([$currentHpp])->filter(fn($v) => (float)$v > 0);
        $minHpp = $allHppValues->isNotEmpty() ? $allHppValues->min() : $currentHpp;
        $maxHpp = $allHppValues->isNotEmpty() ? $allHppValues->max() : $currentHpp;
        $avgHpp = $allHppValues->isNotEmpty() ? round($allHppValues->avg(), 2) : $currentHpp;

        $latestHistory = $historyList->first();

        return [
            'menu' => [
                'id'         => $menu->id,
                'code'       => $menu->code,
                'name'       => $menu->name,
                'category'   => $menu->category,
                'item_type'  => $menu->item_type,
                'price'      => $sellingPrice,
                'cost_price' => (float)($menu->cost_price ?? 0),
                'unit'       => $menu->unit,
            ],
            'current_hpp'           => $currentHpp,
            'selling_price'         => $sellingPrice,
            'current_margin_pct'    => $currentMargin,
            'target_cost_price'     => (float)($menu->cost_price ?? 0),
            'stats'                 => [
                'min_hpp'           => round($minHpp, 2),
                'max_hpp'           => round($maxHpp, 2),
                'avg_hpp'           => round($avgHpp, 2),
                'total_changes'     => $historyList->count(),
                'latest_diff'       => $latestHistory ? $latestHistory->diff : 0.0,
                'latest_pct_change' => $latestHistory ? $latestHistory->percentage_change : 0.0,
                'latest_change_date'=> $latestHistory ? $latestHistory->date->format('Y-m-d') : now()->toDateString(),
            ],
            'ingredients_breakdown' => $ingredientsBreakdown,
            'history'               => $historyList,
        ];
    }

    /**
     * Create an initial baseline record for a menu if no history exists yet.
     */
    protected static function createInitialBaseline(Menu $menu, float $currentHpp, ?int $outletId): ?MenuHppHistory
    {
        if (!Schema::hasTable('menu_hpp_histories')) return null;

        $sellingPrice = (float)($menu->price ?? 0);
        $margin = $sellingPrice > 0 ? round((($sellingPrice - $currentHpp) / $sellingPrice) * 100, 2) : 0.0;

        return MenuHppHistory::create([
            'business_id'        => $menu->business_id,
            'menu_id'            => $menu->id,
            'outlet_id'          => $outletId,
            'date'               => now()->toDateString(),
            'hpp_before'         => $currentHpp,
            'hpp_after'          => $currentHpp,
            'diff'               => 0.0,
            'percentage_change'  => 0.0,
            'selling_price'      => $sellingPrice,
            'margin_before_pct'  => $margin,
            'margin_after_pct'   => $margin,
            'trigger_type'       => 'BASELINE',
            'notes'              => 'Titik acuan awal (baseline) HPP menu saat fitur riwayat diaktifkan.',
            'user_id'            => auth()->id(),
        ]);
    }
}
