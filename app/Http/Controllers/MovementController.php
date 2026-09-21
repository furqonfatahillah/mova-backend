<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use App\Models\Ingredient;
use Illuminate\Http\Request;

class MovementController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        $query = StockMovement::with(['ingredient', 'user', 'creator', 'updater', 'shift', 'outlet', 'transfer.destinationOutlet', 'transfer.sourceOutlet'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($request->ingredient_id) $query->where('ingredient_id', $request->ingredient_id);
        if ($request->type)          $query->where('type', $request->type);
        
        if ($isOutletBounded) {
            $query->where('outlet_id', (int)$user->outlet_id);
        } elseif ($request->filled('outlet_id') && $request->outlet_id !== 'ALL' && $request->outlet_id !== 'all') {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->from)          $query->where('date', '>=', $request->from);
        if ($request->to)            $query->where('date', '<=', $request->to);

        return response()->json($query->limit(300)->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'          => 'required|date',
            'ingredient_id' => 'required|exists:ingredients,id',
            'type'          => 'required|in:PURCHASE,WASTE,ADJUSTMENT_IN,ADJUSTMENT_OUT,TRANSFER_IN,TRANSFER_OUT,PREP_USAGE,PREP_OUTPUT',
            'waste_reason'  => 'nullable|string|max:50',
            'qty'           => 'required|numeric|min:0.001',
            'unit_price'    => 'nullable|numeric|min:0',
            'total_price'   => 'nullable|numeric|min:0',
            'unit_type'     => 'nullable|in:BELI,PAKAI',
            'note'          => 'nullable|string|max:255',
            'outlet_id'     => 'nullable|exists:outlets,id',
        ]);

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = $data['outlet_id'] ?? $user?->outlet_id ?? 1;
        }
        $ingredient = Ingredient::findOrFail($data['ingredient_id']);
        $konversi = max((float)$ingredient->konversi, 1);

        $costBefore = $ingredient->costPerPakaiForOutlet($outletId);
        $costAfter  = $costBefore;
        $unitPrice  = null;
        $totalPrice = null;

        $isUnitBeli = ($request->unit_type === 'BELI');
        $qtyPakai = $isUnitBeli ? (float)$data['qty'] * $konversi : (float)$data['qty'];

        if ($data['type'] === 'PURCHASE') {
            // Determine price per unit pakai and per unit beli
            if ($request->filled('unit_price') && (float)$request->unit_price > 0) {
                $inputPrice = (float)$request->unit_price;
                $pricePerPakai = $isUnitBeli ? ($inputPrice / $konversi) : $inputPrice;
                $pricePerBeli  = $isUnitBeli ? $inputPrice : ($inputPrice * $konversi);
            } elseif ($request->filled('total_price') && (float)$request->total_price > 0 && (float)$data['qty'] > 0) {
                $totalInput = (float)$request->total_price;
                $inputQty = (float)$data['qty'];
                $pricePerBeli = $isUnitBeli ? ($totalInput / $inputQty) : (($totalInput / $inputQty) * $konversi);
                $pricePerPakai = $pricePerBeli / $konversi;
            } else {
                $pricePerBeli  = $ingredient->hargaForOutlet($outletId);
                $pricePerPakai = $pricePerBeli / $konversi;
            }

            $unitPrice  = $pricePerBeli;
            $totalPrice = $request->filled('total_price') && (float)$request->total_price > 0
                ? (float)$request->total_price
                : round(($qtyPakai / $konversi) * $pricePerBeli, 2);

            // Calculate Weighted Moving Average Cost
            $avgResult  = $ingredient->recalculateMovingAverage($qtyPakai, $pricePerPakai, $outletId);
            $costBefore = $avgResult['cost_before'];
            $costAfter  = $avgResult['cost_after'];
        }

        $movement = StockMovement::create([
            'date'          => $data['date'],
            'ingredient_id' => $data['ingredient_id'],
            'outlet_id'     => $outletId,
            'type'          => $data['type'],
            'waste_reason'  => $data['waste_reason'] ?? null,
            'qty'           => $qtyPakai,
            'unit_price'    => $unitPrice,
            'total_price'   => $totalPrice,
            'cost_before'   => $costBefore,
            'cost_after'    => $costAfter,
            'note'          => $data['note'] ?? null,
            'user_id'       => $request->user()->id,
            'created_by'    => $request->user()->id,
        ]);

        $movement->load(['ingredient', 'user', 'creator', 'updater', 'outlet']);
        return response()->json($movement, 201);
    }

    /**
     * Generate Stock Card Summary list for all ingredients in an outlet within a date range
     */
    public function stockCardSummary(Request $request)
    {
        $request->validate([
            'from'      => 'required|date',
            'to'        => 'required|date|after_or_equal:from',
            'outlet_id' => 'nullable|exists:outlets,id',
        ]);

        $from     = $request->from;
        $to       = $request->to;
        $user     = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = $request->outlet_id ? (int)$request->outlet_id : ($user?->outlet_id ?? 1);
        }

        $outlet = \App\Models\Outlet::findOrFail($outletId);

        // Fetch all ingredients of the current business
        $ingredients = Ingredient::with(['outletIngredients' => function($q) use ($outletId) {
            $q->where('outlet_id', $outletId);
        }])->get();

        $ingIds = $ingredients->pluck('id')->all();

        // 1. Group movements strictly before $from for this outlet
        $priorMovements = StockMovement::whereIn('ingredient_id', $ingIds)
            ->where('outlet_id', $outletId)
            ->where('date', '<', $from)
            ->selectRaw("ingredient_id, SUM(CASE WHEN type IN ('INITIAL','PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as prior_sum")
            ->groupBy('ingredient_id')
            ->pluck('prior_sum', 'ingredient_id')
            ->all();

        // 2. Group movements between $from and $to for this outlet
        $periodMovements = StockMovement::whereIn('ingredient_id', $ingIds)
            ->where('outlet_id', $outletId)
            ->whereBetween('date', [$from, $to])
            ->selectRaw("
                ingredient_id,
                SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','PREP_OUTPUT') THEN qty ELSE 0 END) as total_masuk,
                SUM(CASE WHEN type IN ('SALE_USAGE','WASTE','TRANSFER_OUT','ADJUSTMENT_OUT','PREP_USAGE') THEN qty ELSE 0 END) as total_keluar,
                SUM(CASE WHEN type = 'SALE_USAGE' THEN qty ELSE 0 END) as total_penjualan,
                SUM(CASE WHEN type = 'WASTE' THEN qty ELSE 0 END) as total_waste,
                SUM(CASE WHEN type = 'PURCHASE' THEN qty ELSE 0 END) as total_pembelian
            ")
            ->groupBy('ingredient_id')
            ->get()
            ->keyBy('ingredient_id');

        $items = [];
        $grandTotalNilai = 0;
        $totalLowStock = 0;

        foreach ($ingredients as $ing) {
            $outletRow = $ing->outletIngredients->first();
            $stokAwalMaster = $outletRow ? (float) $outletRow->stok_awal : ($outletId === 1 ? (float) $ing->stok_awal : 0.0);
            $stokMinOutlet  = $outletRow && $outletRow->stok_min !== null ? (float) $outletRow->stok_min : (float) $ing->stok_min;

            $priorSum = isset($priorMovements[$ing->id]) ? (float)$priorMovements[$ing->id] : 0.0;
            $stokAwalPeriod = round($stokAwalMaster + $priorSum, 3);

            $periodData = $periodMovements->get($ing->id);
            $totalMasuk = $periodData ? (float)$periodData->total_masuk : 0.0;
            $totalKeluar = $periodData ? (float)$periodData->total_keluar : 0.0;
            $totalPenjualan = $periodData ? (float)$periodData->total_penjualan : 0.0;
            $totalWaste = $periodData ? (float)$periodData->total_waste : 0.0;
            $totalPembelian = $periodData ? (float)$periodData->total_pembelian : 0.0;

            $stokAkhir = round($stokAwalPeriod + $totalMasuk - $totalKeluar, 3);
            $hargaBeliOutlet = $ing->hargaForOutlet($outletId);
            $hargaPerPakai = $hargaBeliOutlet / max($ing->konversi, 1);
            $nilaiStok = round(max($stokAkhir, 0) * $hargaPerPakai, 0);

            $isLow = $stokAkhir <= $stokMinOutlet;
            if ($isLow) $totalLowStock++;
            $grandTotalNilai += $nilaiStok;

            $items[] = [
                'id'              => $ing->id,
                'code'            => $ing->code,
                'name'            => $ing->name,
                'category'        => $ing->category,
                'type'            => $ing->type ?? 'RAW',
                'unit_pakai'      => $ing->unit_pakai,
                'unit_beli'       => $ing->unit_beli,
                'konversi'        => $ing->konversi,
                'harga_beli'      => (float)$hargaBeliOutlet,
                'harga_satuan'    => round($hargaPerPakai, 2),
                'stok_min'        => $stokMinOutlet,
                'stok_awal'       => $stokAwalPeriod,
                'total_masuk'     => $totalMasuk,
                'total_keluar'    => $totalKeluar,
                'total_penjualan' => $totalPenjualan,
                'total_waste'     => $totalWaste,
                'total_pembelian' => $totalPembelian,
                'stok_akhir'      => $stokAkhir,
                'is_low'          => $isLow,
                'is_empty'        => $stokAkhir <= 0,
                'nilai_stok'      => $nilaiStok,
            ];
        }

        return response()->json([
            'outlet' => [
                'id'      => $outlet->id,
                'name'    => $outlet->name,
                'is_main' => (bool)$outlet->is_main,
            ],
            'period' => [
                'from' => $from,
                'to'   => $to,
            ],
            'summary' => [
                'total_items'     => count($items),
                'total_low_stock' => $totalLowStock,
                'total_nilai'     => $grandTotalNilai,
            ],
            'items' => $items,
        ]);
    }

    /**
     * Generate Kartu Stok (Stock Card) ledger for an ingredient in a period
     */
    public function stockCard(Request $request)
    {
        $request->validate([
            'ingredient_id' => 'required|exists:ingredients,id',
            'from'          => 'required|date',
            'to'            => 'required|date|after_or_equal:from',
            'outlet_id'     => 'nullable|exists:outlets,id',
        ]);

        $ingId    = (int) $request->ingredient_id;
        $from     = $request->from;
        $to       = $request->to;
        $user     = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = $request->outlet_id ? (int)$request->outlet_id : ($user?->outlet_id);
        }

        $ingredient = Ingredient::with(['creator', 'updater', 'outletIngredients.outlet'])->findOrFail($ingId);

        // Opening balance and par level calculation per outlet
        if ($outletId) {
            $outletRow = $ingredient->outletIngredients->firstWhere('outlet_id', $outletId);
            $stokAwalMaster = $outletRow ? (float) $outletRow->stok_awal : ($outletId === 1 ? (float) $ingredient->stok_awal : 0.0);
            $stokMinOutlet  = $outletRow && $outletRow->stok_min !== null ? (float) $outletRow->stok_min : (float) $ingredient->stok_min;
        } else {
            // Consolidated across all branches
            $sumInit = (float) $ingredient->outletIngredients->sum('stok_awal');
            $stokAwalMaster = $sumInit > 0 ? $sumInit : (float) $ingredient->stok_awal;
            $stokMinOutlet  = (float) $ingredient->stok_min;
        }

        $outTypes = ['SALE_USAGE', 'WASTE', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'PREP_USAGE'];
        $inTypes  = ['PURCHASE', 'ADJUSTMENT_IN', 'TRANSFER_IN', 'PREP_OUTPUT'];

        // Movements strictly before $from
        $priorQuery = StockMovement::where('ingredient_id', $ingId)->where('date', '<', $from);
        if ($outletId) {
            $priorQuery->where('outlet_id', $outletId);
        }
        $priorMovements = $priorQuery->get();

        $stokAwal = $stokAwalMaster;
        foreach ($priorMovements as $m) {
            $stokAwal += $m->signedQty();
        }

        // Movements in the period ordered chronologically
        $movQuery = StockMovement::with([
            'user', 'creator', 'updater', 'outlet',
            'transaction.menu', 'shift.user', 'shift.closedByUser',
            'transfer.destinationOutlet', 'transfer.sourceOutlet'
        ])
            ->where('ingredient_id', $ingId)
            ->whereBetween('date', [$from, $to]);

        if ($outletId) {
            $movQuery->where('outlet_id', $outletId);
        }

        $movements = $movQuery->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $running = $stokAwal;
        $totalMasuk = 0;
        $totalKeluar = 0;
        $totalPenjualan = 0;
        $totalWaste = 0;
        $totalPembelian = 0;

        $rows = [];
        foreach ($movements as $m) {
            $isOut = in_array($m->type, $outTypes);
            $qtyIn = $isOut ? 0 : (float) $m->qty;
            $qtyOut = $isOut ? (float) $m->qty : 0;

            if ($isOut) {
                $totalKeluar += $qtyOut;
                $running -= $qtyOut;
                if ($m->type === 'SALE_USAGE') $totalPenjualan += $qtyOut;
                if ($m->type === 'WASTE')      $totalWaste += $qtyOut;
            } else {
                $totalMasuk += $qtyIn;
                $running += $qtyIn;
                if ($m->type === 'PURCHASE')   $totalPembelian += $qtyIn;
            }

            $ref = "MVT-{$m->id}";
            if ($m->transfer_id && $m->transfer) {
                $ref = $m->transfer->transfer_no;
            } elseif ($m->shift_id) {
                $ref = "Shift #{$m->shift_id}";
            } elseif ($m->transaction_id) {
                $ref = "TRX #{$m->transaction_id}";
            } elseif ($m->note) {
                $ref = $m->note;
            }

            $creatorName = $m->creator?->name ?? ($m->user?->name ?? ($m->shift?->user?->name ?? 'Sistem'));
            $updaterName = $m->updater?->name ?? ($m->shift?->closedByUser?->name ?? null);
            $hasChanged = ($m->updated_at && $m->created_at && $m->updated_at->ne($m->created_at)) || !empty($updaterName);

            $rows[] = [
                'id'              => $m->id,
                'date'            => $m->date,
                'created_at'      => $m->created_at?->format('Y-m-d H:i:s'),
                'created_time'    => $m->created_at?->format('H:i:s'),
                'created_by'      => $m->created_by,
                'created_by_name' => $creatorName,
                'changed_at'      => $hasChanged ? $m->updated_at?->format('Y-m-d H:i:s') : null,
                'changed_by'      => $m->updated_by,
                'changed_by_name' => $hasChanged ? $updaterName : null,
                'outlet_id'       => $m->outlet_id,
                'outlet_name'     => $m->outlet?->name ?? ($m->outlet_id ? "Outlet #{$m->outlet_id}" : '-'),
                'type'            => $m->type,
                'waste_reason'    => $m->waste_reason,
                'note'            => $m->note,
                'ref'             => $ref,
                'shift_id'        => $m->shift_id,
                'shift_name'      => $m->shift?->shift_name,
                'transaction_id'  => $m->transaction_id,
                'qty_in'          => $qtyIn,
                'qty_out'         => $qtyOut,
                'unit_price'      => $m->unit_price,
                'total_price'     => $m->total_price,
                'cost_before'     => $m->cost_before,
                'cost_after'      => $m->cost_after,
                'balance'         => round($running, 3),
                'user'            => $creatorName,
            ];
        }

        $hargaPerPakai = $ingredient->costPerPakaiForOutlet($outletId);
        $stokAkhir = round($running, 3);
        $outletObj = $outletId ? \App\Models\Outlet::find($outletId) : null;

        return response()->json([
            'ingredient'       => $ingredient,
            'period'           => ['from' => $from, 'to' => $to],
            'outlet_id'        => $outletId,
            'outlet_name'      => $outletObj ? $outletObj->name : 'Semua Cabang (Konsolidasi)',
            'stok_awal'        => round($stokAwal, 3),
            'total_masuk'      => round($totalMasuk, 3),
            'total_keluar'     => round($totalKeluar, 3),
            'total_pembelian'  => round($totalPembelian, 3),
            'total_penjualan'  => round($totalPenjualan, 3),
            'total_waste'      => round($totalWaste, 3),
            'stok_akhir'       => $stokAkhir,
            'stok_min'         => $stokMinOutlet,
            'is_below_min'     => $stokAkhir < $stokMinOutlet,
            'nilai_stok_akhir' => round($stokAkhir * $hargaPerPakai, 0),
            'rows'             => $rows,
        ]);
    }
}
