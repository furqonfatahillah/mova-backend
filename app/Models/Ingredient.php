<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Ingredient extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'code', 'name', 'category', 'category_id', 'type',
        'unit_beli', 'unit_beli_id', 'unit_pakai', 'unit_pakai_id',
        'konversi', 'harga', 'last_purchase_price', 'stok_awal', 'stok_min', 'tolerance',
        'yield_qty', 'yield_unit', 'active',
        'created_by', 'updated_by',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
        // ⚡ PERF: current_stock, current_stok_min, outlet_stocks REMOVED from default appends.
        // These trigger expensive DB queries per ingredient. Append explicitly in controllers that need them.
    ];

    protected $casts = [
        'konversi'            => 'float',
        'harga'               => 'float',
        'last_purchase_price' => 'float',
        'stok_awal'           => 'float',
        'stok_min'            => 'float',
        'tolerance'           => 'float',
        'yield_qty'           => 'float',
        'active'              => 'boolean',
    ];

    public function categoryModel()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function unitBeliModel()
    {
        return $this->belongsTo(Unit::class, 'unit_beli_id');
    }

    public function unitPakaiModel()
    {
        return $this->belongsTo(Unit::class, 'unit_pakai_id');
    }

    public function outletIngredients()
    {
        return $this->hasMany(OutletIngredient::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function opnames()
    {
        return $this->hasMany(Opname::class);
    }

    public function prepRecipe()
    {
        return $this->hasOne(PrepRecipe::class);
    }

    public function prepProductions()
    {
        return $this->hasMany(BatchPrep::class);
    }

    private static ?\Illuminate\Database\Eloquent\Collection $memoizedOutlets = null;

    protected static function getCachedOutlets(): \Illuminate\Database\Eloquent\Collection
    {
        if (static::$memoizedOutlets === null) {
            static::$memoizedOutlets = Outlet::orderBy('id')->get();
        }
        return static::$memoizedOutlets;
    }

    public function stockForOutlet(int $outletId): float
    {
        $outletRow = $this->relationLoaded('outletIngredients')
            ? $this->outletIngredients->firstWhere('outlet_id', $outletId)
            : $this->outletIngredients()->where('outlet_id', $outletId)->first();

        $stokAwal = $outletRow ? (float) $outletRow->stok_awal : ($outletId === 1 ? (float) $this->stok_awal : 0.0);

        if ($this->relationLoaded('movements')) {
            $movSum = (float) $this->movements
                ->where('outlet_id', $outletId)
                ->sum(fn($m) => $m->signedQty());
        } else {
            $movSum = (float) $this->movements()
                ->where('outlet_id', $outletId)
                ->selectRaw("SUM(CASE WHEN type IN ('INITIAL','PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                ->value('net_qty') ?? 0.0;
        }

        return round($stokAwal + $movSum, 3);
    }

    public function consolidatedStock(): float
    {
        if ($this->relationLoaded('movements')) {
            $movSum = (float) $this->movements->sum(fn($m) => $m->signedQty());
        } else {
            $movSum = (float) $this->movements()
                ->selectRaw("SUM(CASE WHEN type IN ('INITIAL','PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                ->value('net_qty') ?? 0.0;
        }

        $initialSum = $this->relationLoaded('outletIngredients')
            ? (float) $this->outletIngredients->sum('stok_awal')
            : (float) $this->outletIngredients()->sum('stok_awal');

        if ($initialSum <= 0) {
            $initialSum = (float) $this->stok_awal;
        }
        return round($initialSum + $movSum, 3);
    }

    public function getCurrentStockAttribute(): float
    {
        $user = auth()->user() ?? auth('sanctum')->user() ?? (app()->bound('request') ? request()->user() : null);
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            return $this->stockForOutlet((int) $user->outlet_id);
        }

        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id');
        if (!$outletId && $user?->outlet_id) {
            $outletId = $user->outlet_id;
        }

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            return $this->stockForOutlet((int) $outletId);
        }

        // If no outlet specified or ALL, return consolidated stock across all outlets
        return $this->consolidatedStock();
    }

    public function getCurrentStokMinAttribute(): float
    {
        $user = auth()->user() ?? auth('sanctum')->user() ?? (app()->bound('request') ? request()->user() : null);
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletRow = $this->relationLoaded('outletIngredients')
                ? $this->outletIngredients->firstWhere('outlet_id', (int)$user->outlet_id)
                : $this->outletIngredients()->where('outlet_id', (int)$user->outlet_id)->first();

            if ($outletRow && $outletRow->stok_min !== null) {
                return (float) $outletRow->stok_min;
            }
            return (float) $this->stok_min;
        }

        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id');
        if (!$outletId && $user?->outlet_id) {
            $outletId = $user->outlet_id;
        }

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $outletRow = $this->relationLoaded('outletIngredients')
                ? $this->outletIngredients->firstWhere('outlet_id', $outletId)
                : $this->outletIngredients()->where('outlet_id', $outletId)->first();

            if ($outletRow && $outletRow->stok_min !== null) {
                return (float) $outletRow->stok_min;
            }
        }

        return (float) $this->stok_min;
    }

    public function getOutletStocksAttribute(): array
    {
        $outlets = static::getCachedOutlets();
        if ($this->relationLoaded('movements')) {
            $movementsGrouped = [];
            foreach ($this->movements as $m) {
                $oid = $m->outlet_id;
                $movementsGrouped[$oid] = ($movementsGrouped[$oid] ?? 0.0) + $m->signedQty();
            }
        } else {
            $movementsGrouped = $this->movements()
                ->selectRaw("outlet_id, SUM(CASE WHEN type IN ('INITIAL','PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                ->groupBy('outlet_id')
                ->pluck('net_qty', 'outlet_id')
                ->all();
        }

        $rows = [];
        $outletIngs = $this->relationLoaded('outletIngredients')
            ? $this->outletIngredients->keyBy('outlet_id')
            : $this->outletIngredients()->get()->keyBy('outlet_id');

        foreach ($outlets as $outlet) {
            $initial = isset($outletIngs[$outlet->id])
                ? (float) $outletIngs[$outlet->id]->stok_awal
                : ($outlet->id === 1 ? (float) $this->stok_awal : 0.0);

            $netMov = isset($movementsGrouped[$outlet->id]) ? (float) $movementsGrouped[$outlet->id] : 0.0;
            $current = round($initial + $netMov, 3);

            $min = isset($outletIngs[$outlet->id]) && $outletIngs[$outlet->id]->stok_min !== null
                ? (float) $outletIngs[$outlet->id]->stok_min
                : (float) $this->stok_min;

            $outletHarga = isset($outletIngs[$outlet->id]) && $outletIngs[$outlet->id]->harga !== null
                ? (float) $outletIngs[$outlet->id]->harga
                : (float) $this->harga;

            $outletLastPrice = isset($outletIngs[$outlet->id]) && $outletIngs[$outlet->id]->last_purchase_price !== null
                ? (float) $outletIngs[$outlet->id]->last_purchase_price
                : (float) $this->last_purchase_price;

            $rows[] = [
                'outlet_id'           => $outlet->id,
                'outlet_code'         => $outlet->code,
                'outlet_name'         => $outlet->name,
                'is_main'             => (bool) $outlet->is_main,
                'stok_awal'           => $initial,
                'stok_min'            => $min,
                'stock'               => $current,
                'current'             => $current,
                'current_stock'       => $current,
                'is_low'              => $current <= $min,
                'harga'               => $outletHarga,
                'last_purchase_price' => $outletLastPrice,
            ];
        }

        return $rows;
    }

    public function getCurrentHargaAttribute(): float
    {
        $user = auth()->user() ?? auth('sanctum')->user() ?? (app()->bound('request') ? request()->user() : null);
        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id');
        if (!$outletId && $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = $user->outlet_id;
        }

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            return $this->hargaForOutlet((int) $outletId);
        }

        return (float) $this->harga;
    }

    public function hargaForOutlet(?int $outletId = null): float
    {
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $outletRow = $this->relationLoaded('outletIngredients')
                ? $this->outletIngredients->firstWhere('outlet_id', (int)$outletId)
                : $this->outletIngredients()->where('outlet_id', (int)$outletId)->first();

            if ($outletRow && $outletRow->harga !== null && (float)$outletRow->harga > 0) {
                return (float)$outletRow->harga;
            }
        }
        return (float)$this->harga;
    }

    public function costPerPakaiForOutlet(?int $outletId = null): float
    {
        return $this->hargaForOutlet($outletId) / max((float)$this->konversi, 1);
    }

    public function lastPurchasePriceForOutlet(?int $outletId = null): ?float
    {
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $outletRow = $this->relationLoaded('outletIngredients')
                ? $this->outletIngredients->firstWhere('outlet_id', (int)$outletId)
                : $this->outletIngredients()->where('outlet_id', (int)$outletId)->first();

            if ($outletRow && $outletRow->last_purchase_price !== null) {
                return (float)$outletRow->last_purchase_price;
            }
        }
        return $this->last_purchase_price !== null ? (float)$this->last_purchase_price : null;
    }

    /**
     * Recalculate Weighted Moving Average Cost when new inventory is purchased or transferred
     * Formula:
     * Current Stock * Current Cost + Incoming Qty * Incoming Price
     * -------------------------------------------------------------
     *                Current Stock + Incoming Qty
     */
    public function recalculateMovingAverage(float $incomingQtyPakai, float $incomingPricePerPakai, ?int $outletId = null): array
    {
        $konversi = max((float)$this->konversi, 1);
        $costBefore = $this->costPerPakaiForOutlet($outletId);

        // Current stock on hand for this outlet
        $currentStock = $outletId ? $this->stockForOutlet($outletId) : $this->consolidatedStock();
        $effectiveStock = max($currentStock, 0.0);

        if ($effectiveStock + $incomingQtyPakai > 0) {
            $oldValue = $effectiveStock * $costBefore;
            $newValue = $incomingQtyPakai * $incomingPricePerPakai;
            $costAfter = ($oldValue + $newValue) / ($effectiveStock + $incomingQtyPakai);
        } else {
            $costAfter = $incomingPricePerPakai;
        }

        $newHargaBeli = round($costAfter * $konversi, 2);
        $purchasePricePerUnitBeli = round($incomingPricePerPakai * $konversi, 2);

        // ⚡ ISOLATED MULTI-OUTLET COSTING:
        // Update the specific outlet record so that other branches are NOT affected!
        if ($outletId) {
            $outletRow = OutletIngredient::firstOrNew([
                'outlet_id'     => (int)$outletId,
                'ingredient_id' => $this->id,
            ]);
            $outletRow->harga = $newHargaBeli;
            $outletRow->last_purchase_price = $purchasePricePerUnitBeli;
            $outletRow->save();

            if ($this->relationLoaded('outletIngredients')) {
                $this->load('outletIngredients');
            }
        }

        // Only update master harga if it's the main outlet or master harga is not yet set
        $isMainOutlet = false;
        if ($outletId) {
            $targetOutlet = static::getCachedOutlets()->firstWhere('id', (int)$outletId);
            $isMainOutlet = (bool)($targetOutlet?->is_main || (int)$outletId === 1);
        }
        if ($isMainOutlet || !$outletId || (float)$this->harga <= 0) {
            $this->harga = $newHargaBeli;
            $this->last_purchase_price = $purchasePricePerUnitBeli;
            $this->save();
        }

        // ⚡ Record Menu HPP changes for any recipes that use this ingredient
        try {
            \App\Services\MenuHppService::recordForIngredientCostChange(
                $this,
                (float)$costBefore,
                (float)$costAfter,
                $outletId ? (int)$outletId : null
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Gagal mencatat riwayat HPP menu saat recalculateMovingAverage: " . $e->getMessage());
        }

        return [
            'cost_before' => round($costBefore, 4),
            'cost_after'  => round($costAfter, 4),
            'new_harga'   => $newHargaBeli,
            'last_price'  => $purchasePricePerUnitBeli,
        ];
    }
}
