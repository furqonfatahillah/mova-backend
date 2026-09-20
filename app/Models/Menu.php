<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Menu extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'code',
        'barcode',
        'name',
        'description',
        'category',
        'category_id',
        'item_type',    // RECIPE, DIRECT, SERVICE
        'track_stock',
        'stock',
        'min_stock',
        'price',
        'cost_price',
        'unit',
        'unit_id',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
        'current_stock',
        'current_min_stock',
        'is_recipe',
        'is_direct',
        'is_service',
        'is_bundle',
    ];

    protected $casts = [
        'price'       => 'float',
        'cost_price'  => 'float',
        'stock'       => 'float',
        'min_stock'   => 'float',
        'track_stock' => 'boolean',
        'active'      => 'boolean',
    ];

    public function categoryModel()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function unitModel()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function bundleItems()
    {
        return $this->hasMany(BundleItem::class, 'menu_id')->with(['bundledMenu', 'ingredient']);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function outletMenus()
    {
        return $this->hasMany(OutletMenu::class);
    }

    public function modifierGroups()
    {
        return $this->belongsToMany(ModifierGroup::class, 'menu_modifier_groups')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->with(['options.ingredient']);
    }

    /** Active recipe at or before a given date */
    public function activeRecipe(?string $atDate = null): ?Recipe
    {
        if ($this->relationLoaded('recipes')) {
            return $this->recipes
                ->when($atDate, fn($c) => $c->where('date', '<=', $atDate))
                ->sortByDesc('version')
                ->first();
        }

        return $this->recipes()
            ->when($atDate, fn($q) => $q->where('date', '<=', $atDate))
            ->orderByDesc('version')
            ->first();
    }

    /** Hitung stok spesifik per outlet untuk barang DIRECT/Retail */
    public function stockForOutlet(?int $outletId): float
    {
        if (!$outletId || $outletId === 0) {
            return (float)($this->stock ?? 0);
        }

        try {
            $outletRow = $this->relationLoaded('outletMenus')
                ? $this->outletMenus->firstWhere('outlet_id', $outletId)
                : $this->outletMenus()->where('outlet_id', $outletId)->first();

            if ($outletRow) {
                return (float)$outletRow->stock;
            }
        } catch (\Throwable $e) {
            // Fallback jika tabel outlet_menus belum dimigrasi di server
        }

        return (float)($this->stock ?? 0);
    }

    /** Hitung stok minimum spesifik per outlet */
    public function minStockForOutlet(?int $outletId): float
    {
        if (!$outletId || $outletId === 0) {
            return (float)($this->min_stock ?? 0);
        }

        try {
            $outletRow = $this->relationLoaded('outletMenus')
                ? $this->outletMenus->firstWhere('outlet_id', $outletId)
                : $this->outletMenus()->where('outlet_id', $outletId)->first();

            if ($outletRow && $outletRow->min_stock !== null) {
                return (float)$outletRow->min_stock;
            }
        } catch (\Throwable $e) {
            // Fallback jika tabel outlet_menus belum dimigrasi di server
        }

        return (float)($this->min_stock ?? 0);
    }

    public function getCurrentStockAttribute(): float
    {
        $user = auth()->user() ?? auth('sanctum')->user() ?? (app()->bound('request') ? request()->user() : null);
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            return $this->stockForOutlet((int)$user->outlet_id);
        }

        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id');
        if (!$outletId && $user?->outlet_id) {
            $outletId = $user->outlet_id;
        }

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            return $this->stockForOutlet((int)$outletId);
        }

        try {
            // Konsolidasi seluruh outlet atau stok master
            $outletSum = $this->relationLoaded('outletMenus')
                ? (float)$this->outletMenus->sum('stock')
                : (float)$this->outletMenus()->sum('stock');

            if ($outletSum > 0) {
                return $outletSum;
            }
        } catch (\Throwable $e) {
            // Fallback jika tabel outlet_menus belum dimigrasi di server
        }

        return (float)($this->stock ?? 0);
    }

    public function getCurrentMinStockAttribute(): float
    {
        $user = auth()->user() ?? auth('sanctum')->user() ?? (app()->bound('request') ? request()->user() : null);
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            return $this->minStockForOutlet((int)$user->outlet_id);
        }

        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id');
        if (!$outletId && $user?->outlet_id) {
            $outletId = $user->outlet_id;
        }

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            return $this->minStockForOutlet((int)$outletId);
        }

        return (float)($this->min_stock ?? 0);
    }

    public function getIsRecipeAttribute(): bool
    {
        return ($this->item_type ?? 'RECIPE') === 'RECIPE';
    }

    public function getIsDirectAttribute(): bool
    {
        return $this->item_type === 'DIRECT';
    }

    public function getIsServiceAttribute(): bool
    {
        return $this->item_type === 'SERVICE';
    }

    public function getIsBundleAttribute(): bool
    {
        return $this->item_type === 'BUNDLE';
    }

    public function costPriceForOutlet(?int $outletId = null): float
    {
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $om = $this->relationLoaded('outletMenus')
                ? $this->outletMenus->firstWhere('outlet_id', (int)$outletId)
                : $this->outletMenus()->where('outlet_id', (int)$outletId)->first();

            if ($om && $om->cost_price !== null && (float)$om->cost_price > 0) {
                return (float)$om->cost_price;
            }
        }
        return (float)($this->cost_price ?? 0);
    }

    /**
     * Hitung HPP / Cost of Goods Sold untuk produk ini (mendukung isolasi HPP per cabang)
     */
    public function calculateHpp(?string $date = null, ?int $outletId = null): float
    {
        // 1. Jika menu bertipe BUNDLE / Combo, hitung total HPP dari seluruh item di dalamnya
        if ($this->is_bundle) {
            $sum = 0.0;
            foreach ($this->bundleItems as $bi) {
                if ($bi->bundledMenu) {
                    $sum += $bi->bundledMenu->calculateHpp($date, $outletId) * (float)$bi->qty;
                } elseif ($bi->ingredient) {
                    $ing = $bi->ingredient;
                    $hargaPakai = $ing->costPerPakaiForOutlet($outletId);
                    $sum += (float)$bi->qty * $hargaPakai;
                }
            }
            return round($sum, 2);
        }

        // 2. Jika barang retail direct atau jasa, gunakan cost_price cabang terkait
        if ($this->is_direct || $this->is_service) {
            return $this->costPriceForOutlet($outletId);
        }

        // 3. Jika olahan resep (RECIPE), hitung total harga bahan baku cabang terkait
        $recipe = $this->activeRecipe($date);
        if ($recipe) {
            $sum = 0.0;
            foreach ($recipe->items as $item) {
                $ing = $item->ingredient;
                if ($ing) {
                    $hargaPakai = $ing->costPerPakaiForOutlet($outletId);
                    $sum += (float)$item->qty * $hargaPakai;
                }
            }
            return round($sum, 2);
        }

        // Fallback jika belum memiliki resep
        return $this->costPriceForOutlet($outletId);
    }
}
