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
        'item_type',    // RECIPE, DIRECT, SERVICE
        'track_stock',
        'stock',
        'min_stock',
        'price',
        'cost_price',
        'unit',
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
    ];

    protected $casts = [
        'price'       => 'float',
        'cost_price'  => 'float',
        'stock'       => 'float',
        'min_stock'   => 'float',
        'track_stock' => 'boolean',
        'active'      => 'boolean',
    ];

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
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
        return $this->recipes()
            ->when($atDate, fn($q) => $q->where('date', '<=', $atDate))
            ->orderByDesc('version')
            ->first();
    }

    /** Hitung stok spesifik per outlet untuk barang DIRECT/Retail */
    public function stockForOutlet(?int $outletId): float
    {
        if (!$outletId || $outletId === 0) {
            return (float)$this->stock;
        }

        $outletRow = $this->outletMenus()->where('outlet_id', $outletId)->first();
        if ($outletRow) {
            return (float)$outletRow->stock;
        }

        return (float)$this->stock;
    }

    /** Hitung stok minimum spesifik per outlet */
    public function minStockForOutlet(?int $outletId): float
    {
        if (!$outletId || $outletId === 0) {
            return (float)$this->min_stock;
        }

        $outletRow = $this->outletMenus()->where('outlet_id', $outletId)->first();
        if ($outletRow && $outletRow->min_stock !== null) {
            return (float)$outletRow->min_stock;
        }

        return (float)$this->min_stock;
    }

    public function getCurrentStockAttribute(): float
    {
        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id');
        if (!$outletId && auth()->check() && auth()->user()->outlet_id) {
            $outletId = auth()->user()->outlet_id;
        }

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            return $this->stockForOutlet((int)$outletId);
        }

        // Konsolidasi seluruh outlet atau stok master
        $outletSum = (float)$this->outletMenus()->sum('stock');
        return $outletSum > 0 ? $outletSum : (float)$this->stock;
    }

    public function getCurrentMinStockAttribute(): float
    {
        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id');
        if (!$outletId && auth()->check() && auth()->user()->outlet_id) {
            $outletId = auth()->user()->outlet_id;
        }

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            return $this->minStockForOutlet((int)$outletId);
        }

        return (float)$this->min_stock;
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

    /**
     * Hitung HPP / Cost of Goods Sold untuk produk ini
     */
    public function calculateHpp(?string $date = null): float
    {
        // 1. Jika barang retail direct atau jasa, gunakan cost_price langsung
        if ($this->is_direct || $this->is_service) {
            return (float)$this->cost_price;
        }

        // 2. Jika olahan resep (RECIPE), hitung total harga bahan baku
        $recipe = $this->activeRecipe($date);
        if ($recipe) {
            $sum = 0.0;
            foreach ($recipe->items as $item) {
                $ing = $item->ingredient;
                if ($ing) {
                    $hargaPakai = (float)$ing->harga / max((float)$ing->konversi, 1);
                    $sum += (float)$item->qty * $hargaPakai;
                }
            }
            return round($sum, 2);
        }

        // Fallback jika belum memiliki resep
        return (float)($this->cost_price ?? 0);
    }
}
