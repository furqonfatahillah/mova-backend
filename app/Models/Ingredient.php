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
        'konversi', 'harga', 'tolerance',
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
        'tolerance'           => 'float',
        'yield_qty'           => 'float',
        'active'              => 'boolean',
    ];

    protected static function booted(): void
    {
    }

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

    public function initialBalances()
    {
        return $this->hasMany(IngredientInitialBalance::class);
    }

    public function getStokAwalAttribute(): float
    {
        if ($this->relationLoaded('initialBalances')) {
            return (float) $this->initialBalances->sum('stok_awal');
        }
        return (float) ($this->initialBalances()->sum('stok_awal') ?? 0);
    }

    public function getSaldoAwalNominalAttribute(): float
    {
        if ($this->relationLoaded('initialBalances')) {
            return (float) $this->initialBalances->sum('saldo_awal_nominal');
        }
        return (float) ($this->initialBalances()->sum('saldo_awal_nominal') ?? 0);
    }

    public function getTanggalSaldoAwalAttribute(): ?string
    {
        if ($this->relationLoaded('initialBalances')) {
            return $this->initialBalances->firstWhere('tanggal_saldo_awal', '!=', null)?->tanggal_saldo_awal;
        }
        return $this->initialBalances()->whereNotNull('tanggal_saldo_awal')->value('tanggal_saldo_awal');
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

    protected ?array $precomputedMovements = null;

    public function setPrecomputedMovements(array $map): self
    {
        $this->precomputedMovements = $map;
        return $this;
    }

    public function stockForOutlet(int $outletId): float
    {
        $outletRow = $this->relationLoaded('outletIngredients')
            ? $this->outletIngredients->firstWhere('outlet_id', $outletId)
            : $this->outletIngredients()->where('outlet_id', $outletId)->first();

        // ⚡ O(1) Fast-Path: Use denormalized current_stock column directly without scanning stock_movements table
        if ($outletRow && $outletRow->current_stock !== null) {
            return round((float) $outletRow->current_stock, 3);
        }

        // Dynamic calculation fallback (for unmigrated records or custom precomputed maps)
        $initRow = $this->relationLoaded('initialBalances')
            ? $this->initialBalances->firstWhere('outlet_id', $outletId)
            : $this->initialBalances()->where('outlet_id', $outletId)->first();
        $stokAwal = $initRow ? (float)$initRow->stok_awal : 0.0;

        if ($this->precomputedMovements !== null) {
            $movSum = (float) ($this->precomputedMovements[$outletId] ?? 0.0);
        } elseif ($this->relationLoaded('movements')) {
            $movSum = (float) $this->movements
                ->where('outlet_id', $outletId)
                ->where('type', '!=', 'INITIAL')
                ->sum(fn($m) => $m->signedQty());
        } else {
            $movSum = (float) $this->movements()
                ->where('outlet_id', $outletId)
                ->where('type', '!=', 'INITIAL')
                ->selectRaw("SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                ->value('net_qty') ?? 0.0;
        }

        return round($stokAwal + $movSum, 3);
    }

    public function consolidatedStock(): float
    {
        if ($this->relationLoaded('outletIngredients')) {
            return round((float) $this->outletIngredients->sum('current_stock'), 3);
        }

        return round((float) $this->outletIngredients()->sum('current_stock'), 3);
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
            return 0.0;
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

        return 0.0;
    }

    public function getStokMinAttribute(): float
    {
        return $this->getCurrentStokMinAttribute();
    }

    public function getLastPurchasePriceAttribute(): ?float
    {
        $user = auth()->user() ?? auth('sanctum')->user() ?? (app()->bound('request') ? request()->user() : null);
        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id') ?? $user?->outlet_id;
        if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
            $outletRow = $this->relationLoaded('outletIngredients')
                ? $this->outletIngredients->firstWhere('outlet_id', (int)$outletId)
                : $this->outletIngredients()->where('outlet_id', (int)$outletId)->first();

            if ($outletRow && $outletRow->last_purchase_price !== null) {
                return (float)$outletRow->last_purchase_price;
            }
        }

        $anyRow = $this->relationLoaded('outletIngredients')
            ? $this->outletIngredients->firstWhere('last_purchase_price', '>', 0)
            : $this->outletIngredients()->where('last_purchase_price', '>', 0)->first();

        return $anyRow ? (float)$anyRow->last_purchase_price : null;
    }

    public function getOutletStocksAttribute(): array
    {
        $outlets = static::getCachedOutlets();
        $outletIngs = $this->relationLoaded('outletIngredients')
            ? $this->outletIngredients->keyBy('outlet_id')
            : $this->outletIngredients()->get()->keyBy('outlet_id');

        $initialBalancesKeyed = $this->relationLoaded('initialBalances')
            ? $this->initialBalances->keyBy('outlet_id')
            : $this->initialBalances()->get()->keyBy('outlet_id');

        $movementsGrouped = null;

        $rows = [];
        foreach ($outlets as $outlet) {
            $initRow = $initialBalancesKeyed[$outlet->id] ?? null;
            $outletRow = $outletIngs[$outlet->id] ?? null;
            $initial = $initRow ? (float)$initRow->stok_awal : 0.0;
            $initNominal = $initRow ? (float)$initRow->saldo_awal_nominal : 0.0;
            $initTanggal = $initRow?->tanggal_saldo_awal;

            // ⚡ O(1) Fast-Path: Use denormalized current_stock if present
            if ($outletRow && $outletRow->current_stock !== null) {
                $current = round((float)$outletRow->current_stock, 3);
            } else {
                // Lazy load movements aggregation only if missing current_stock
                if ($movementsGrouped === null) {
                    if ($this->precomputedMovements !== null) {
                        $movementsGrouped = $this->precomputedMovements;
                    } elseif ($this->relationLoaded('movements')) {
                        $movementsGrouped = [];
                        foreach ($this->movements as $m) {
                            if ($m->type === 'INITIAL') continue;
                            $oid = $m->outlet_id;
                            $movementsGrouped[$oid] = ($movementsGrouped[$oid] ?? 0.0) + $m->signedQty();
                        }
                    } else {
                        $movementsGrouped = $this->movements()
                            ->where('type', '!=', 'INITIAL')
                            ->selectRaw("outlet_id, SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                            ->groupBy('outlet_id')
                            ->pluck('net_qty', 'outlet_id')
                            ->all();
                    }
                }

                $netMov = isset($movementsGrouped[$outlet->id]) ? (float) $movementsGrouped[$outlet->id] : 0.0;
                $current = round($initial + $netMov, 3);
            }

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
                'saldo_awal_nominal'  => $initNominal,
                'tanggal_saldo_awal'  => $initTanggal,
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
    public function recomputeMovingAverageFromHistory(int $outletId, ?array &$visitedOutlets = null): array
    {
        if ($visitedOutlets === null) {
            $visitedOutlets = [];
        }
        if (in_array($outletId, $visitedOutlets)) {
            return [];
        }
        $visitedOutlets[] = $outletId;

        $konversi = max((float)$this->konversi, 1);
        $initRow = IngredientInitialBalance::where('outlet_id', $outletId)->where('ingredient_id', $this->id)->first();
        $outletRow = OutletIngredient::where('outlet_id', $outletId)->where('ingredient_id', $this->id)->first();
        $this->refresh();
        $ot = \App\Models\Outlet::find($outletId);
        $isHolding = $ot ? (bool)($ot->is_holding || $ot->is_main) : false;

        $initialStock = $initRow ? (float)$initRow->stok_awal : 0.0;
        $initialNominal = $initRow ? (float)$initRow->saldo_awal_nominal : 0.0;

        $initialHarga = $outletRow && $outletRow->harga !== null ? (float)$outletRow->harga : (float)$this->harga;
        if ($initialNominal > 0 && $initialStock > 0) {
            $initialCostPerPakai = $initialNominal / $initialStock;
        } elseif ($initialStock > 0) {
            $initialCostPerPakai = $initialHarga / $konversi;
            $initialNominal = round($initialStock * $initialCostPerPakai, 2);
        } else {
            $initialCostPerPakai = 0.0;
            $initialNominal = 0.0;
        }

        // Update record INITIAL movement jika ada
        StockMovement::where('ingredient_id', $this->id)
            ->where('outlet_id', $outletId)
            ->where('type', 'INITIAL')
            ->update([
                'cost_before' => round($initialCostPerPakai, 4),
                'cost_after'  => round($initialCostPerPakai, 4),
                'unit_price'  => round($initialCostPerPakai * $konversi, 4),
                'total_price' => round($initialNominal, 2),
            ]);

        $runningStock = max($initialStock, 0.0);
        $runningNominal = $initialNominal;
        $runningCostPerPakai = $initialCostPerPakai;
        $lastPurchasePrice = round($initialCostPerPakai * $konversi, 2);

        // Ambil seluruh pergerakan stok untuk bahan dan outlet ini secara kronologis
        $movements = StockMovement::where('ingredient_id', $this->id)
            ->where('outlet_id', $outletId)
            ->where('type', '!=', 'INITIAL')
            ->orderBy('date', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $downstreamOutlets = [];

        foreach ($movements as $m) {
            $qty = (float)$m->qty;
            if (in_array($m->type, ['PURCHASE', 'TRANSFER_IN', 'PREP_OUTPUT'])) {
                // Mutasi masuk yang mempengaruhi moving average
                $incomingPricePerBeli = (float)($m->unit_price ?: ($m->total_price > 0 && $qty > 0 ? $m->total_price / ($qty / $konversi) : $runningCostPerPakai * $konversi));
                $incomingPricePerPakai = $incomingPricePerBeli / $konversi;
                $lastPurchasePrice = $incomingPricePerBeli;

                $costBefore = $runningCostPerPakai;

                if ($m->type === 'TRANSFER_IN') {
                    $inVal = round(($qty / $konversi) * $incomingPricePerBeli, 2);
                } elseif ($m->total_price > 0 && $qty > 0) {
                    $inVal = (float)$m->total_price;
                } else {
                    $inVal = $qty * $incomingPricePerPakai;
                }

                $runningStock += $qty;
                $runningNominal += $inVal;
                $costAfter = $runningStock > 0 ? ($runningNominal / $runningStock) : $incomingPricePerPakai;

                $m->cost_before = round($costBefore, 4);
                $m->cost_after = round($costAfter, 4);
                $m->unit_price = round($incomingPricePerBeli, 4);
                $m->total_price = round($inVal, 2);
                $m->saveQuietly();

                $runningCostPerPakai = $costAfter;
            } elseif (in_array($m->type, ['ADJUSTMENT_IN', 'ADJUSTMENT_PLUS'])) {
                $costBefore = $runningCostPerPakai;

                // ─── Penentuan harga unit untuk adjustment ─────────────────────────────
                // Jika stok SEDANG DEFISIT (negatif) dan adjustment ini menutup/mengurangi
                // defisit tersebut, kita harus mengunci avg sebelumnya untuk me-0-kan defisit.
                // Jika adjustment melebihi defisit (menjadi stok positif), bagian kelebihan (surplus)
                // baru mengambil avg berdasarkan harga yang diinputkan pengguna.
                // ───────────────────────────────────────────────────────────────────────
                if ($runningStock < -0.0001) {
                    $deficitQty = abs($runningStock);
                    $deficitNominal = abs($runningNominal);
                    $deficitAvg = $deficitQty > 0.0001 ? ($deficitNominal / $deficitQty) : $runningCostPerPakai;

                    $projectedStock = $runningStock + $qty;

                    if ($projectedStock <= 0.0001) {
                        // Menutup sebagian atau persis seluruh defisit:
                        // Nilai masuk (inVal) persis menutup nilai defisit secara proporsional atau penuh
                        $inVal = ($deficitQty > 0.0001) ? ($deficitNominal * ($qty / $deficitQty)) : 0;
                        $incomingPricePerPakai = $deficitAvg;
                        $incomingPricePerBeli  = $deficitAvg * $konversi;
                    } else {
                        // Adjustment menutup defisit DAN berlanjut ke stok positif (> 0):
                        // 1. Bagian me-0-kan defisit mengunci avg lama
                        $closeDeficitVal = $deficitNominal;
                        // 2. Bagian sisa positif mengambil avg berdasarkan apa yang diinputkan
                        $surplusQty = $projectedStock;

                        $uPrice = (float)$m->unit_price;
                        $benchmarkCost = $runningCostPerPakai > 0 ? $runningCostPerPakai : ($initialCostPerPakai > 0 ? $initialCostPerPakai : ((float)$this->harga / $konversi));

                        if ($konversi > 1 && $uPrice > 0 && $benchmarkCost > 0 && $uPrice <= ($benchmarkCost * 2.5)) {
                            $inputPricePerPakai = $uPrice;
                            $inputPricePerBeli  = $uPrice * $konversi;
                        } elseif ($uPrice > 0) {
                            $inputPricePerBeli  = $uPrice;
                            $inputPricePerPakai = $inputPricePerBeli / $konversi;
                        } elseif ($m->total_price > 0 && $qty > 0) {
                            $inputPricePerPakai = (float)$m->total_price / $qty;
                            $inputPricePerBeli  = $inputPricePerPakai * $konversi;
                        } else {
                            $inputPricePerPakai = $benchmarkCost;
                            $inputPricePerBeli  = $benchmarkCost * $konversi;
                        }

                        $surplusVal = $surplusQty * $inputPricePerPakai;
                        $inVal = $closeDeficitVal + $surplusVal;
                        $incomingPricePerPakai = $surplusVal / $surplusQty;
                        $incomingPricePerBeli  = $inputPricePerBeli;
                    }
                } else {
                    // Stok normal (positif atau nol): gunakan unit_price tersimpan atau runningCostPerPakai
                    $uPrice = (float)$m->unit_price;
                    $benchmarkCost = $runningCostPerPakai > 0 ? $runningCostPerPakai : ($initialCostPerPakai > 0 ? $initialCostPerPakai : ((float)$this->harga / $konversi));

                    if ($konversi > 1 && $uPrice > 0 && $benchmarkCost > 0 && $uPrice <= ($benchmarkCost * 2.5)) {
                        $incomingPricePerPakai = $uPrice;
                        $incomingPricePerBeli  = $uPrice * $konversi;
                        $inVal = $qty * $incomingPricePerPakai;
                    } elseif ($uPrice > 0) {
                        $incomingPricePerBeli  = $uPrice;
                        $incomingPricePerPakai = $incomingPricePerBeli / $konversi;
                        $inVal = $qty * $incomingPricePerPakai;
                    } elseif ($m->total_price > 0 && $qty > 0) {
                        $inVal = (float)$m->total_price;
                        $incomingPricePerPakai = $inVal / $qty;
                        $incomingPricePerBeli  = $incomingPricePerPakai * $konversi;
                    } else {
                        $inVal = $qty * $runningCostPerPakai;
                        $incomingPricePerBeli  = $runningCostPerPakai * $konversi;
                        $incomingPricePerPakai = $runningCostPerPakai;
                    }
                }

                $runningStock += $qty;
                $runningNominal += $inVal;

                // Jika stok persis 0 (misal setelah normalisasi), pastikan nominal bersih 0 dan avg terkunci
                if (abs($runningStock) <= 0.0001) {
                    $runningStock = 0.0;
                    $runningNominal = 0.0;
                    $costAfter = $runningCostPerPakai;
                } else {
                    $costAfter = $runningStock > 0.0001 ? ($runningNominal / $runningStock) : $runningCostPerPakai;
                }

                $m->cost_before = round($costBefore, 4);
                $m->cost_after = round($costAfter, 4);
                $m->unit_price = round($incomingPricePerPakai * $konversi, 4);
                $m->total_price = round($inVal, 2);
                $m->saveQuietly();

                $runningCostPerPakai = $costAfter;
            } elseif ($m->type === 'TRANSFER_OUT') {
                // Transfer keluar: HPP dan harga transfer diperbarui sesuai moving average cabang asal saat transaksi terjadi
                $currentPricePerBeli = round($runningCostPerPakai * $konversi, 2);
                $currentTotalPrice = round(($qty / $konversi) * $currentPricePerBeli, 2);

                $m->unit_price  = $currentPricePerBeli;
                $m->total_price = $currentTotalPrice;
                $m->cost_before = round($runningCostPerPakai, 4);
                $m->cost_after  = round($runningCostPerPakai, 4);
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
                        $pmQty = (float)$pm->qty;
                        $pmTotalPrice = round(($pmQty / $konversi) * $currentPricePerBeli, 2);

                        $pm->unit_price  = $currentPricePerBeli;
                        $pm->total_price = $pmTotalPrice;
                        $pm->saveQuietly();

                        $destOid = (int)$pm->outlet_id;
                        if ($destOid && !in_array($destOid, $downstreamOutlets) && !in_array($destOid, $visitedOutlets)) {
                            $downstreamOutlets[] = $destOid;
                        }
                    }
                }

                $runningStock -= $qty;
                $runningNominal -= $currentTotalPrice;
                if (abs($runningStock) <= 0.0001) {
                    $runningStock = 0.0;
                    $runningNominal = 0.0;
                }
            } else {
                // Mutasi keluar lainnya (SALE_USAGE, WASTE, ADJUSTMENT_OUT, PREP_USAGE)
                $outVal = $qty * $runningCostPerPakai;
                $m->cost_before = round($runningCostPerPakai, 4);
                $m->cost_after = round($runningCostPerPakai, 4);
                $m->unit_price = round($runningCostPerPakai * $konversi, 4);
                $m->total_price = round($outVal, 2);
                $m->saveQuietly();

                $runningStock -= $qty;
                $runningNominal -= $outVal;
                if (abs($runningStock) <= 0.0001) {
                    $runningStock = 0.0;
                    $runningNominal = 0.0;
                }
            }
        }

        $newHargaBeli = round($runningCostPerPakai * $konversi, 2);

        if ($outletRow) {
            $outletRow->current_stock = round($runningStock, 3);
            $outletRow->harga = $newHargaBeli;
            $outletRow->last_purchase_price = $lastPurchasePrice;
            $outletRow->save();
        }

        $isMainOutlet = (bool)(Outlet::find($outletId)?->is_main || $outletId === 1);
        if ($isMainOutlet) {
            $this->harga = $newHargaBeli;
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
            'cost_per_pakai' => round($runningCostPerPakai, 4),
            'harga_beli'     => $newHargaBeli,
            'stock'          => round($runningStock, 3),
        ];
    }

    /**
     * Check if this ingredient is classified as a semi-finished product (bahan setengah jadi / olahan)
     */
    public function isSemiFinished(): bool
    {
        if ($this->type === 'SEMI_FINISHED') {
            return true;
        }

        if ($this->relationLoaded('prepRecipe')) {
            if ($this->prepRecipe !== null) return true;
        } elseif ($this->prepRecipe()->exists()) {
            return true;
        }

        $cat = strtoupper(trim($this->category ?? ''));
        return in_array($cat, ['SEMI_FINISHED', 'SEMI-FINISHED', 'BAHAN SETENGAH JADI', 'BAHAN OLAHAN', 'OLAHAN', 'PREP']);
    }

    /**
     * Check if this ingredient has an initial balance (saldo awal)
     * either in master table, any outlet_ingredients, or initial stock movements
     */
    public function hasInitialBalance(?int $outletId = null): bool
    {
        // 1. If checking for a specific outlet, evaluate ONLY that outlet's records
        if ($outletId) {
            $initRow = $this->relationLoaded('initialBalances')
                ? $this->initialBalances->firstWhere('outlet_id', $outletId)
                : $this->initialBalances()->where('outlet_id', $outletId)->first();

            if ($initRow && ((float)($initRow->stok_awal ?? 0) > 0 || (float)($initRow->saldo_awal_nominal ?? 0) > 0 || !empty($initRow->tanggal_saldo_awal))) {
                return true;
            }

            $oi = $this->relationLoaded('outletIngredients')
                ? $this->outletIngredients->firstWhere('outlet_id', $outletId)
                : $this->outletIngredients()->where('outlet_id', $outletId)->first();

            if ($oi && (float)($oi->current_stock ?? 0) > 0) {
                return true;
            }

            if ($this->relationLoaded('movements')) {
                return $this->movements->contains(function ($m) use ($outletId) {
                    return (int)$m->outlet_id === (int)$outletId
                        && (float)$m->qty > 0
                        && in_array($m->type, ['INITIAL', 'PREP_OUTPUT', 'PURCHASE', 'TRANSFER_IN', 'ADJUSTMENT_IN', 'ADJUSTMENT_PLUS']);
                });
            }

            return StockMovement::where('ingredient_id', $this->id)
                ->where('outlet_id', $outletId)
                ->where('qty', '>', 0)
                ->whereIn('type', ['INITIAL', 'PREP_OUTPUT', 'PURCHASE', 'TRANSFER_IN', 'ADJUSTMENT_IN', 'ADJUSTMENT_PLUS'])
                ->exists();
        }

        // 2. Consolidated check across all outlets
        if ($this->relationLoaded('initialBalances')) {
            $hasInInit = $this->initialBalances->contains(function ($init) {
                return (float)($init->stok_awal ?? 0) > 0 || (float)($init->saldo_awal_nominal ?? 0) > 0 || !empty($init->tanggal_saldo_awal);
            });
            if ($hasInInit) {
                return true;
            }
        } else {
            $hasInInit = $this->initialBalances()->where(function ($q) {
                $q->where('stok_awal', '>', 0)
                  ->orWhere('saldo_awal_nominal', '>', 0)
                  ->orWhereNotNull('tanggal_saldo_awal');
            })->exists();

            if ($hasInInit) {
                return true;
            }
        }

        if ($this->relationLoaded('outletIngredients')) {
            $hasInAnyOutlet = $this->outletIngredients->contains(fn($oi) => (float)($oi->current_stock ?? 0) > 0);
            if ($hasInAnyOutlet) {
                return true;
            }
        } else {
            $hasInAnyOutlet = $this->outletIngredients()->where('current_stock', '>', 0)->exists();
            if ($hasInAnyOutlet) {
                return true;
            }
        }

        if ((float)($this->current_stock ?? 0) > 0) {
            return true;
        }

        return false;
    }

    public function getIsSemiFinishedAttribute(): bool
    {
        return $this->isSemiFinished();
    }

    public function getHasInitialBalanceAttribute(): bool
    {
        $user = auth()->user() ?? auth('sanctum')->user() ?? (app()->bound('request') ? request()->user() : null);
        $outletId = request()->query('outlet_id') ?? request()->header('X-Outlet-Id');
        if (!$outletId && $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = $user->outlet_id;
        }
        $targetOutletId = ($outletId && $outletId !== 'ALL' && $outletId !== 'all') ? (int)$outletId : null;
        return $this->hasInitialBalance($targetOutletId);
    }

    /**
     * Ditiadakan: Jika saldo awal 0 kemudian ada barang masuk, saldo awal tetap 0
     * dan barang masuk menjadi baris mutasi transaksi baru (tidak mengupdate saldo awal).
     */
    public function autoAdoptFirstPositiveMovementAsInitialBalance(?int $outletId = null): bool
    {
        return false;
    }
}

