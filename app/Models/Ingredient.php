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
        'code', 'name', 'category', 'type', 'unit_beli', 'unit_pakai',
        'konversi', 'harga', 'last_purchase_price', 'stok_awal', 'stok_min', 'tolerance',
        'yield_qty', 'yield_unit', 'active',
        'created_by', 'updated_by',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
        'current_stock',
        'current_stok_min',
        'outlet_stocks',
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

    public function stockForOutlet(int $outletId): float
    {
        $outletRow = $this->outletIngredients()->where('outlet_id', $outletId)->first();
        $stokAwal = $outletRow ? (float) $outletRow->stok_awal : ($outletId === 1 ? (float) $this->stok_awal : 0.0);

        $movSum = (float) $this->movements()
            ->where('outlet_id', $outletId)
            ->get()
            ->sum(fn($m) => $m->signedQty());

        return round($stokAwal + $movSum, 3);
    }

    public function consolidatedStock(): float
    {
        $movSum = (float) $this->movements()->get()->sum(fn($m) => $m->signedQty());
        $initialSum = (float) $this->outletIngredients()->sum('stok_awal');
        if ($initialSum <= 0) {
            $initialSum = (float) $this->stok_awal;
        }
        return round($initialSum + $movSum, 3);
    }

    public function getCurrentStockAttribute(): float
    {
        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id');
        if (!$outletId && auth()->check() && auth()->user()->outlet_id) {
            $outletId = auth()->user()->outlet_id;
        }

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            return $this->stockForOutlet((int) $outletId);
        }

        // If no outlet specified or ALL, return consolidated stock across all outlets
        return $this->consolidatedStock();
    }

    public function getCurrentStokMinAttribute(): float
    {
        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id');
        if (!$outletId && auth()->check() && auth()->user()->outlet_id) {
            $outletId = auth()->user()->outlet_id;
        }

        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $outletRow = $this->outletIngredients()->where('outlet_id', $outletId)->first();
            if ($outletRow && $outletRow->stok_min !== null) {
                return (float) $outletRow->stok_min;
            }
        }

        return (float) $this->stok_min;
    }

    public function getOutletStocksAttribute(): array
    {
        $outlets = Outlet::orderBy('id')->get();
        $movementsGrouped = $this->movements()
            ->selectRaw("outlet_id, SUM(CASE WHEN type IN ('INITIAL','PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
            ->groupBy('outlet_id')
            ->pluck('net_qty', 'outlet_id')
            ->all();

        $rows = [];
        $outletIngs = $this->outletIngredients()->get()->keyBy('outlet_id');

        foreach ($outlets as $outlet) {
            $initial = isset($outletIngs[$outlet->id])
                ? (float) $outletIngs[$outlet->id]->stok_awal
                : ($outlet->id === 1 ? (float) $this->stok_awal : 0.0);

            $netMov = isset($movementsGrouped[$outlet->id]) ? (float) $movementsGrouped[$outlet->id] : 0.0;
            $current = round($initial + $netMov, 3);

            $min = isset($outletIngs[$outlet->id]) && $outletIngs[$outlet->id]->stok_min !== null
                ? (float) $outletIngs[$outlet->id]->stok_min
                : (float) $this->stok_min;

            $rows[] = [
                'outlet_id'   => $outlet->id,
                'outlet_code' => $outlet->code,
                'outlet_name' => $outlet->name,
                'is_main'     => (bool) $outlet->is_main,
                'stok_awal'   => $initial,
                'stok_min'    => $min,
                'stock'       => $current,
                'is_low'      => $current <= $min,
            ];
        }

        return $rows;
    }

    /**
     * Recalculate Weighted Moving Average Cost when new inventory is purchased
     * Formula:
     * Current Stock * Current Cost + Incoming Qty * Incoming Price
     * -------------------------------------------------------------
     *                Current Stock + Incoming Qty
     */
    public function recalculateMovingAverage(float $incomingQtyPakai, float $incomingPricePerPakai, ?int $outletId = null): array
    {
        $konversi = max((float)$this->konversi, 1);
        $costBefore = (float)$this->harga / $konversi;

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

        $this->harga = $newHargaBeli;
        $this->last_purchase_price = $purchasePricePerUnitBeli;
        $this->save();

        return [
            'cost_before' => round($costBefore, 4),
            'cost_after'  => round($costAfter, 4),
            'new_harga'   => $newHargaBeli,
            'last_price'  => $purchasePricePerUnitBeli,
        ];
    }
}
