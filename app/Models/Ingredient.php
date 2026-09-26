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
     * Formula: Perpetual Weighted Moving Average based on latest running stock balance
     */
    public function recalculateMovingAverage(float $incomingQtyPakai, float $incomingPricePerPakai, ?int $outletId = null): array
    {
        $konversi = max((float)$this->konversi, 1);
        $costBefore = $this->costPerPakaiForOutlet($outletId);

        // Ambil saldo stok fisik terakhir sebelum barang masuk baru ini
        $currentStockBefore = $outletId ? $this->stockForOutlet((int)$outletId) : $this->consolidatedStock();

        // ⚡ PERPETUAL WEIGHTED MOVING AVERAGE:
        // Jika sisa stok sebelumnya <= 0 (kosong / minus):
        // Harga baru langsung mengadopsi harga beli barang masuk baru.
        if ($currentStockBefore <= 0) {
            $costAfter = $incomingPricePerPakai;
        } else {
            $currentValue = $currentStockBefore * $costBefore;
            $incomingValue = $incomingQtyPakai * $incomingPricePerPakai;
            $newTotalQty = $currentStockBefore + $incomingQtyPakai;
            $costAfter = $newTotalQty > 0 ? (($currentValue + $incomingValue) / $newTotalQty) : $incomingPricePerPakai;
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
            'cost_before' => round($costBefore, 2),
            'cost_after'  => round($costAfter, 2),
            'new_harga'   => $newHargaBeli,
            'last_price'  => $purchasePricePerUnitBeli,
        ];
    }

    /**
     * Hitung ulang Moving Average dari awal riwayat mutasi bahan pada suatu outlet secara kronologis.
     * Mengalirkan (cascade) pembaruan HPP transfer keluar ke cabang tujuan penerima secara otomatis.
     */
    public function recomputeMovingAverageFromHistory(int $outletId, array &$visitedOutlets = []): array
    {
        if (in_array($outletId, $visitedOutlets)) {
            return [];
        }
        $visitedOutlets[] = $outletId;

        $konversi = max((float)$this->konversi, 1);
        $outletRow = OutletIngredient::where('outlet_id', $outletId)->where('ingredient_id', $this->id)->first();
        $initialStock = $outletRow ? (float)$outletRow->stok_awal : ($outletId === 1 ? (float)$this->stok_awal : 0.0);
        $initialHarga = $outletRow && $outletRow->harga !== null ? (float)$outletRow->harga : (float)$this->harga;
        $initialCostPerPakai = $initialHarga / $konversi;

        $runningStock = max($initialStock, 0.0);
        $runningCostPerPakai = $initialCostPerPakai;
        $lastPurchasePrice = $initialHarga;

        // Ambil seluruh pergerakan stok untuk bahan dan outlet ini secara kronologis
        $movements = StockMovement::where('ingredient_id', $this->id)
            ->where('outlet_id', $outletId)
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $downstreamOutlets = [];

        foreach ($movements as $m) {
            $qty = (float)$m->qty;
            if (in_array($m->type, ['PURCHASE', 'TRANSFER_IN', 'PREP_OUTPUT', 'INITIAL'])) {
                // Mutasi masuk yang mempengaruhi moving average
                $incomingPricePerBeli = (float)($m->unit_price ?: ($m->total_price > 0 && $qty > 0 ? $m->total_price / ($qty / $konversi) : $runningCostPerPakai * $konversi));
                $incomingPricePerPakai = $incomingPricePerBeli / $konversi;
                $lastPurchasePrice = $incomingPricePerBeli;

                $costBefore = $runningCostPerPakai;

                // ⚡ PERPETUAL WEIGHTED MOVING AVERAGE:
                // Mengambil nilai sisa saldo stok terakhir sebelum mutasi masuk ini
                if ($runningStock <= 0) {
                    $costAfter = $incomingPricePerPakai;
                } else {
                    $stockBeforeVal = $runningStock * $costBefore;
                    $incomingVal = $qty * $incomingPricePerPakai;
                    $newTotalQty = $runningStock + $qty;
                    $costAfter = $newTotalQty > 0 ? (($stockBeforeVal + $incomingVal) / $newTotalQty) : $incomingPricePerPakai;
                }

                $m->cost_before = round($costBefore, 2);
                $m->cost_after = round($costAfter, 2);
                $m->saveQuietly();

                $runningCostPerPakai = $costAfter;
                $runningStock += $qty;
            } elseif (in_array($m->type, ['ADJUSTMENT_IN', 'ADJUSTMENT_PLUS'])) {
                $costBefore = $runningCostPerPakai;
                if ($m->unit_price > 0) {
                    $incomingPricePerBeli = (float)$m->unit_price;
                    $incomingPricePerPakai = $incomingPricePerBeli / $konversi;
                    if ($runningStock <= 0) {
                        $costAfter = $incomingPricePerPakai;
                    } else {
                        $stockBeforeVal = $runningStock * $costBefore;
                        $incomingVal = $qty * $incomingPricePerPakai;
                        $newTotalQty = $runningStock + $qty;
                        $costAfter = $newTotalQty > 0 ? (($stockBeforeVal + $incomingVal) / $newTotalQty) : $runningCostPerPakai;
                    }
                } else {
                    $costAfter = $runningCostPerPakai;
                }

                $m->cost_before = round($costBefore, 2);
                $m->cost_after = round($costAfter, 2);
                $m->saveQuietly();

                $runningCostPerPakai = $costAfter;
                $runningStock += $qty;
            } elseif ($m->type === 'TRANSFER_OUT') {
                // Transfer keluar: HPP dan harga transfer diperbarui sesuai moving average cabang asal saat transaksi terjadi
                $currentPricePerBeli = round($runningCostPerPakai * $konversi, 2);
                $currentTotalPrice = round(($qty / $konversi) * $currentPricePerBeli, 2);

                $m->unit_price  = $currentPricePerBeli;
                $m->total_price = $currentTotalPrice;
                $m->cost_before = round($runningCostPerPakai, 2);
                $m->cost_after  = round($runningCostPerPakai, 2);
                $m->saveQuietly();

                if ($m->transfer_id) {
                    // 1. Update TransferItem
                    \App\Models\TransferItem::where('transfer_id', $m->transfer_id)
                        ->where('ingredient_id', $this->id)
                        ->update([
                            'unit_price'  => $currentPricePerBeli,
                            'total_price' => $currentTotalPrice,
                        ]);

                    // 2. Update paired TRANSFER_IN di cabang penerima (tujuan)
                    $pairedMovements = StockMovement::where('transfer_id', $m->transfer_id)
                        ->where('ingredient_id', $this->id)
                        ->where('type', 'TRANSFER_IN')
                        ->where('id', '!=', $m->id)
                        ->get();

                    foreach ($pairedMovements as $pm) {
                        $pm->unit_price  = $currentPricePerBeli;
                        $pm->total_price = $currentTotalPrice;
                        $pm->saveQuietly();

                        $destOid = (int)$pm->outlet_id;
                        if ($destOid && !in_array($destOid, $downstreamOutlets) && !in_array($destOid, $visitedOutlets)) {
                            $downstreamOutlets[] = $destOid;
                        }
                    }
                }

                $runningStock -= $qty;
            } else {
                // Mutasi keluar lainnya (SALE_USAGE, WASTE, ADJUSTMENT_OUT, PREP_USAGE)
                // Barang keluar hanya mengambil nilai rata-rata terakhir untuk digunakan
                $m->cost_before = round($runningCostPerPakai, 2);
                $m->cost_after = round($runningCostPerPakai, 2);
                $m->saveQuietly();

                $runningStock -= $qty;
            }
        }

        $newHargaBeli = round($runningCostPerPakai * $konversi, 2);

        if ($outletRow) {
            $outletRow->harga = $newHargaBeli;
            $outletRow->last_purchase_price = $lastPurchasePrice;
            $outletRow->save();
        }

        $isMainOutlet = (bool)(Outlet::find($outletId)?->is_main || $outletId === 1);
        if ($isMainOutlet) {
            $this->harga = $newHargaBeli;
            $this->last_purchase_price = $lastPurchasePrice;
            $this->save();
        }

        // Cascade update akumulasi ulang ke seluruh cabang penerima transfer
        foreach ($downstreamOutlets as $destOid) {
            $this->recomputeMovingAverageFromHistory($destOid, $visitedOutlets);
        }

        // Sinkronisasi update riwayat HPP Menu
        try {
            \App\Services\MenuHppService::recordForIngredientCostChange(
                $this,
                (float)$initialCostPerPakai,
                (float)$runningCostPerPakai,
                $outletId
            );
        } catch (\Throwable $e) {}

        return [
            'cost_per_pakai' => round($runningCostPerPakai, 2),
            'harga_beli'     => $newHargaBeli,
            'stock'          => round($runningStock, 3),
        ];
    }
}
