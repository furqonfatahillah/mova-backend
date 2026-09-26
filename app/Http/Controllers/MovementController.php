<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\OutletIngredient;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'date'           => 'required|date',
            'ingredient_id'  => 'required|exists:ingredients,id',
            'type'           => 'required|in:PURCHASE,WASTE,ADJUSTMENT_IN,ADJUSTMENT_OUT,TRANSFER_IN,TRANSFER_OUT,PREP_USAGE,PREP_OUTPUT',
            'waste_reason'   => 'nullable|string|max:50',
            'qty'            => 'required|numeric|min:0.001',
            'unit_price'     => 'nullable|numeric|min:0',
            'total_price'    => 'nullable|numeric|min:0',
            'unit_type'      => 'nullable|in:BELI,PAKAI',
            'note'           => 'nullable|string|max:255',
            'outlet_id'      => 'nullable|exists:outlets,id',
            // Payment / Hutang fields
            'payment_type'   => 'nullable|string|max:50',
            'supplier_name'  => 'nullable|string|max:150',
            'supplier_phone' => 'nullable|string|max:50',
            'supplier_id'    => 'nullable|integer',
            'purchase_no'    => 'nullable|string|max:100',
            'due_date'       => 'nullable|date',
            'initial_paid'   => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:50',
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

        $rawPaymentType = strtoupper(trim($request->payment_type ?? 'CASH'));
        $isHutang = in_array($rawPaymentType, ['HUTANG', 'TEMPO']);
        $paymentType = $isHutang ? 'HUTANG' : ($rawPaymentType === 'TRANSFER' ? 'TRANSFER' : 'CASH');
        $payableId = null;

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

            // Handle Hutang Supplier (Accounts Payable)
            if ($isHutang) {
                $businessId = $user?->business_id;
                $supplierName = trim($data['supplier_name'] ?? 'Supplier Umum');
                $supplierPhone = $data['supplier_phone'] ?? null;
                $supplierId = $data['supplier_id'] ?? null;

                if (!$supplierId && !empty($supplierName)) {
                    $supplier = Supplier::firstOrCreate(
                        ['business_id' => $businessId, 'name' => $supplierName],
                        ['phone' => $supplierPhone, 'active' => true]
                    );
                    $supplierId = $supplier->id;
                }

                $dueDate = $data['due_date'] ?? Carbon::parse($data['date'])->addDays(30)->toDateString();
                $initialPaid = min((float)($data['initial_paid'] ?? 0), (float)$totalPrice);
                $remaining = max(0, (float)$totalPrice - $initialPaid);
                $status = ($remaining <= 0) ? 'PAID' : ($initialPaid > 0 ? 'PARTIAL' : 'UNPAID');

                $payableNo = Payable::generatePayableNo($businessId, $data['date']);
                $payable = Payable::create([
                    'payable_no'       => $payableNo,
                    'purchase_no'      => $data['purchase_no'] ?? null,
                    'business_id'      => $businessId,
                    'outlet_id'        => $outletId,
                    'supplier_id'      => $supplierId,
                    'supplier_name'    => $supplierName,
                    'supplier_phone'   => $supplierPhone,
                    'ingredient_id'    => $data['ingredient_id'],
                    'issue_date'       => $data['date'],
                    'due_date'         => $dueDate,
                    'total_amount'     => $totalPrice,
                    'paid_amount'      => $initialPaid,
                    'remaining_amount' => $remaining,
                    'status'           => $status,
                    'notes'            => "Pembelian Stok Bahan: {$ingredient->name}" . (!empty($data['note']) ? " ({$data['note']})" : ''),
                    'created_by'       => $user->id,
                ]);

                $payableId = $payable->id;

                if ($initialPaid > 0) {
                    PayablePayment::create([
                        'payment_no'     => PayablePayment::generatePaymentNo($businessId, $data['date']),
                        'payable_id'     => $payable->id,
                        'business_id'    => $businessId,
                        'outlet_id'      => $outletId,
                        'payment_date'   => $data['date'],
                        'amount'         => $initialPaid,
                        'payment_method' => $data['payment_method'] ?? 'CASH',
                        'notes'          => 'Uang Muka (DP) Pembelian Bahan',
                        'paid_by'        => $user->id,
                    ]);
                }
            }
        }

        $movement = StockMovement::create([
            'date'          => $data['date'],
            'ingredient_id' => $data['ingredient_id'],
            'outlet_id'     => $outletId,
            'type'          => $data['type'],
            'payment_type'  => $paymentType,
            'supplier_name' => $data['supplier_name'] ?? null,
            'purchase_no'   => $data['purchase_no'] ?? null,
            'payable_id'    => $payableId,
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

        if (isset($payable)) {
            $payable->update(['stock_movement_id' => $movement->id]);
        }

        $movement->load(['ingredient', 'user', 'creator', 'updater', 'outlet', 'payable']);
        return response()->json($movement, 201);
    }

    /**
     * Store multiple stock movements at once (e.g. multi-item purchase receipt or multi-item mutations).
     */
    public function bulkStore(Request $request)
    {
        $validated = $request->validate([
            'date'                  => 'required|date',
            'outlet_id'             => 'nullable|exists:outlets,id',
            'type'                  => 'required|in:PURCHASE,WASTE,ADJUSTMENT_IN,ADJUSTMENT_OUT,TRANSFER_IN,TRANSFER_OUT,PREP_USAGE,PREP_OUTPUT',
            'payment_type'          => 'nullable|string|max:50', // CASH, BANK, TRANSFER, QRIS, HUTANG
            'supplier_name'         => 'nullable|string|max:150',
            'supplier_phone'        => 'nullable|string|max:50',
            'supplier_id'           => 'nullable|integer',
            'purchase_no'           => 'nullable|string|max:100',
            'due_date'              => 'nullable|date',
            'initial_paid'          => 'nullable|numeric|min:0',
            'payment_method'        => 'nullable|string|max:50',
            'notes'                 => 'nullable|string|max:500',
            'items'                 => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|exists:ingredients,id',
            'items.*.qty'           => 'required|numeric|min:0.0001',
            'items.*.unit_type'     => 'nullable|in:BELI,PAKAI',
            'items.*.unit_price'    => 'nullable|numeric|min:0',
            'items.*.total_price'   => 'nullable|numeric|min:0',
            'items.*.waste_reason'  => 'nullable|string|max:50',
            'items.*.note'          => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = $validated['outlet_id'] ?? $user?->outlet_id ?? 1;
        }
        $businessId = $user?->business_id;

        $rawPaymentType = strtoupper(trim($validated['payment_type'] ?? 'CASH'));
        $isHutang = in_array($rawPaymentType, ['HUTANG', 'TEMPO']);
        $paymentType = $isHutang ? 'HUTANG' : (in_array($rawPaymentType, ['BANK', 'TRANSFER', 'QRIS']) ? $rawPaymentType : 'CASH');

        $result = DB::transaction(function () use ($validated, $user, $outletId, $businessId, $isHutang, $paymentType) {
            $itemsData = [];
            $grandTotal = 0.0;
            $itemNames = [];

            // 1. Process calculations for each ingredient
            foreach ($validated['items'] as $item) {
                $ingredient = Ingredient::findOrFail($item['ingredient_id']);
                $konversi = max((float)$ingredient->konversi, 1);
                $isUnitBeli = (($item['unit_type'] ?? 'BELI') === 'BELI');
                $qtyPakai = $isUnitBeli ? (float)$item['qty'] * $konversi : (float)$item['qty'];

                $costBefore = $ingredient->costPerPakaiForOutlet($outletId);
                $costAfter = $costBefore;
                $unitPrice = null;
                $totalPrice = null;

                if ($validated['type'] === 'PURCHASE') {
                    if (isset($item['unit_price']) && (float)$item['unit_price'] > 0) {
                        $inputPrice = (float)$item['unit_price'];
                        $pricePerPakai = $isUnitBeli ? ($inputPrice / $konversi) : $inputPrice;
                        $pricePerBeli  = $isUnitBeli ? $inputPrice : ($inputPrice * $konversi);
                    } elseif (isset($item['total_price']) && (float)$item['total_price'] > 0 && (float)$item['qty'] > 0) {
                        $totalInput = (float)$item['total_price'];
                        $inputQty = (float)$item['qty'];
                        $pricePerBeli = $isUnitBeli ? ($totalInput / $inputQty) : (($totalInput / $inputQty) * $konversi);
                        $pricePerPakai = $pricePerBeli / $konversi;
                    } else {
                        $pricePerBeli  = $ingredient->hargaForOutlet($outletId);
                        $pricePerPakai = $pricePerBeli / $konversi;
                    }

                    $unitPrice = $pricePerBeli;
                    $totalPrice = isset($item['total_price']) && (float)$item['total_price'] > 0
                        ? (float)$item['total_price']
                        : round(($qtyPakai / $konversi) * $pricePerBeli, 2);

                    $grandTotal += $totalPrice;

                    // Recalculate moving average
                    $avgResult = $ingredient->recalculateMovingAverage($qtyPakai, $pricePerPakai, $outletId);
                    $costBefore = $avgResult['cost_before'];
                    $costAfter = $avgResult['cost_after'];
                }

                $itemNames[] = $ingredient->name . ' (' . (float)$item['qty'] . ' ' . ($isUnitBeli ? ($ingredient->unit_beli ?: 'unit') : ($ingredient->unit_pakai ?: 'unit')) . ')';

                $itemsData[] = [
                    'ingredient'   => $ingredient,
                    'qty_pakai'    => $qtyPakai,
                    'unit_price'   => $unitPrice,
                    'total_price'  => $totalPrice,
                    'cost_before'  => $costBefore,
                    'cost_after'   => $costAfter,
                    'waste_reason' => $item['waste_reason'] ?? null,
                    'note'         => $item['note'] ?? null,
                ];
            }

            // 2. If HUTANG, create single consolidated Payable for the whole invoice
            $payableId = null;
            $payable = null;
            if ($isHutang && $validated['type'] === 'PURCHASE') {
                $supplierName = trim($validated['supplier_name'] ?? 'Supplier Umum');
                $supplierPhone = $validated['supplier_phone'] ?? null;
                $supplierId = $validated['supplier_id'] ?? null;

                if (!$supplierId && !empty($supplierName)) {
                    $supplier = Supplier::firstOrCreate(
                        ['business_id' => $businessId, 'name' => $supplierName],
                        ['phone' => $supplierPhone, 'active' => true]
                    );
                    $supplierId = $supplier->id;
                }

                $dueDate = $validated['due_date'] ?? Carbon::parse($validated['date'])->addDays(30)->toDateString();
                $initialPaid = min((float)($validated['initial_paid'] ?? 0), (float)$grandTotal);
                $remaining = max(0, (float)$grandTotal - $initialPaid);
                $status = ($remaining <= 0) ? 'PAID' : ($initialPaid > 0 ? 'PARTIAL' : 'UNPAID');

                $payableNo = Payable::generatePayableNo($businessId, $validated['date']);
                $payable = Payable::create([
                    'payable_no'       => $payableNo,
                    'purchase_no'      => $validated['purchase_no'] ?? null,
                    'business_id'      => $businessId,
                    'outlet_id'        => $outletId,
                    'supplier_id'      => $supplierId,
                    'supplier_name'    => $supplierName,
                    'supplier_phone'   => $supplierPhone,
                    'ingredient_id'    => $itemsData[0]['ingredient']->id ?? null,
                    'issue_date'       => $validated['date'],
                    'due_date'         => $dueDate,
                    'total_amount'     => $grandTotal,
                    'paid_amount'      => $initialPaid,
                    'remaining_amount' => $remaining,
                    'status'           => $status,
                    'notes'            => 'Pembelian Multi-Bahan: ' . implode(', ', array_slice($itemNames, 0, 5)) . (count($itemNames) > 5 ? ' dkk.' : '') . (!empty($validated['notes']) ? " ({$validated['notes']})" : ''),
                    'created_by'       => $user?->id,
                ]);

                $payableId = $payable->id;

                if ($initialPaid > 0) {
                    PayablePayment::create([
                        'payment_no'     => PayablePayment::generatePaymentNo($businessId, $validated['date']),
                        'payable_id'     => $payable->id,
                        'business_id'    => $businessId,
                        'outlet_id'      => $outletId,
                        'payment_date'   => $validated['date'],
                        'amount'         => $initialPaid,
                        'payment_method' => $validated['payment_method'] ?? 'CASH',
                        'notes'          => 'Uang Muka (DP) Pembelian Multi-Bahan',
                        'paid_by'        => $user?->id,
                    ]);
                }
            }

            // 3. Create StockMovement for each item
            $movements = [];
            foreach ($itemsData as $it) {
                $movement = StockMovement::create([
                    'business_id'   => $businessId,
                    'date'          => $validated['date'],
                    'ingredient_id' => $it['ingredient']->id,
                    'outlet_id'     => $outletId,
                    'type'          => $validated['type'],
                    'payment_type'  => $paymentType,
                    'supplier_name' => $validated['supplier_name'] ?? null,
                    'purchase_no'   => $validated['purchase_no'] ?? null,
                    'payable_id'    => $payableId,
                    'waste_reason'  => $it['waste_reason'],
                    'qty'           => $it['qty_pakai'],
                    'unit_price'    => $it['unit_price'],
                    'total_price'   => $it['total_price'],
                    'cost_before'   => $it['cost_before'],
                    'cost_after'    => $it['cost_after'],
                    'note'          => $it['note'] ?? ($validated['notes'] ?? null),
                    'user_id'       => $user?->id,
                    'created_by'    => $user?->id,
                ]);
                $movements[] = $movement;
            }

            if ($payable && count($movements) > 0) {
                $payable->update(['stock_movement_id' => $movements[0]->id]);
            }

            return [
                'movements'    => $movements,
                'payable'      => $payable,
                'grand_total'  => $grandTotal,
                'total_items'  => count($movements),
            ];
        });

        return response()->json([
            'message'     => 'Berhasil mencatat ' . $result['total_items'] . ' mutasi stok' . ($isHutang ? ' & dicatat ke Buku Hutang Supplier.' : '.'),
            'total_items' => $result['total_items'],
            'grand_total' => $result['grand_total'],
            'payable'     => $result['payable'],
            'movements'   => $result['movements'],
        ], 201);
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
                'payment_type'    => $m->payment_type ?? 'CASH',
                'supplier_name'   => $m->supplier_name,
                'purchase_no'     => $m->purchase_no,
                'payable_id'      => $m->payable_id,
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

    /**
     * Bulk Import Initial Stock (Saldo Awal Stok) for software transition / onboarding
     */
    public function bulkImportInitial(Request $request)
    {
        $request->validate([
            'items'   => 'required|array|min:1',
            'items.*' => 'required|array',
        ]);

        $user = $request->user();
        $businessId = $user?->business_id;

        $items = $request->items;
        $importedCount = 0;

        $businessOutlets = Outlet::where('business_id', $businessId)->get();
        $mainOutlet = $businessOutlets->firstWhere('is_main', true) ?? $businessOutlets->first();

        // If user is restricted to a specific outlet
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;

        DB::transaction(function () use ($items, $businessId, $user, $businessOutlets, $mainOutlet, $isOutletBounded, &$importedCount) {
            foreach ($items as $row) {
                if (empty($row['name'])) continue;

                $name = trim($row['name']);
                $lowerName = strtolower($name);

                if (
                    str_starts_with($name, '===') ||
                    str_contains($lowerName, 'template import') ||
                    str_contains($lowerName, 'petunjuk') ||
                    str_contains($lowerName, 'saldo awal stok') ||
                    in_array($lowerName, ['kode bahan / item', 'nama bahan / item', 'nama bahan', 'nama item', 'nama bahan*', 'nama bahan / item*'])
                ) {
                    continue;
                }

                $code = !empty($row['code']) ? trim($row['code']) : null;

                // Match ingredient by code or name within business
                $ingredient = null;
                if (!empty($code)) {
                    $ingredient = Ingredient::where('business_id', $businessId)->where('code', $code)->first();
                }
                if (!$ingredient) {
                    $ingredient = Ingredient::where('business_id', $businessId)
                        ->where(DB::raw('LOWER(name)'), $lowerName)
                        ->first();
                }

                $rawUnit = trim($row['unit'] ?? '');
                $unitType = strtoupper(trim($row['unit_type'] ?? ''));
                $initialStock = (float)($row['initial_stock'] ?? $row['stok_awal'] ?? $row['qty'] ?? 0);
                $harga = (float)($row['harga'] ?? $row['price'] ?? $row['harga_beli'] ?? 0);
                $minStock = isset($row['stok_min']) && $row['stok_min'] !== '' ? (float)$row['stok_min'] : null;

                // If ingredient doesn't exist yet, auto-create it with clean defaults
                if (!$ingredient) {
                    if (empty($code)) {
                        $seq = Ingredient::where('business_id', $businessId)->count() + 1;
                        do {
                            $candidateCode = 'BHN-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                            $exists = Ingredient::where('business_id', $businessId)->where('code', $candidateCode)->exists();
                            $seq++;
                        } while ($exists);
                        $code = $candidateCode;
                    }

                    // Check if perlengkapan
                    $isPerlengkapan = false;
                    $perlengkapanKeywords = ['cup', 'pipet', 'sedotan', 'tissue', 'tisu', 'kantong', 'kresek', 'box', 'kemasan', 'packaging', 'lid', 'sealer', 'dus'];
                    foreach ($perlengkapanKeywords as $kw) {
                        if (str_contains($lowerName, $kw)) {
                            $isPerlengkapan = true;
                            break;
                        }
                    }

                    $catName = $isPerlengkapan ? 'Perlengkapan' : 'BAHAN_BAKU';
                    $cat = Category::firstOrCreate(
                        ['business_id' => $businessId, 'name' => $catName, 'type' => 'INGREDIENT'],
                        ['slug' => Str::slug($catName), 'color' => '#00B14F', 'icon' => 'Package']
                    );

                    $uBeliInfo = $this->resolveUnit($rawUnit ?: ($isPerlengkapan ? 'slop' : 'kg'), $businessId, $isPerlengkapan ? 'slop' : 'kg');
                    $uPakaiInfo = $this->resolveUnit($isPerlengkapan ? 'pcs' : 'gram', $businessId, $isPerlengkapan ? 'pcs' : 'gram');

                    $konversi = 1.0;
                    if ($uBeliInfo['symbol'] === 'kg' && $uPakaiInfo['symbol'] === 'gram') $konversi = 1000.0;
                    elseif ($uBeliInfo['symbol'] === 'liter' && $uPakaiInfo['symbol'] === 'ml') $konversi = 1000.0;
                    elseif ($uBeliInfo['symbol'] === 'slop' && $uPakaiInfo['symbol'] === 'pcs') $konversi = 50.0;
                    elseif ($uBeliInfo['symbol'] === 'pack') $konversi = 100.0;

                    $ingredient = Ingredient::create([
                        'business_id'   => $businessId,
                        'code'          => $code,
                        'name'          => $name,
                        'category_id'   => $cat->id,
                        'category'      => $cat->name,
                        'type'          => 'RAW',
                        'unit_beli'     => $uBeliInfo['symbol'],
                        'unit_pakai'    => $uPakaiInfo['symbol'],
                        'unit_beli_id'  => $uBeliInfo['id'],
                        'unit_pakai_id' => $uPakaiInfo['id'],
                        'konversi'      => $konversi,
                        'harga'         => $harga,
                        'stok_min'      => $minStock ?? 0,
                        'stok_awal'     => 0,
                        'created_by'    => $user?->id,
                        'updated_by'    => $user?->id,
                    ]);
                }

                // Resolve Target Outlet
                $targetOutlet = null;
                if ($isOutletBounded) {
                    $targetOutlet = $businessOutlets->firstWhere('id', (int)$user->outlet_id) ?? $mainOutlet;
                } else {
                    $outletInput = trim($row['outlet_name'] ?? $row['outlet'] ?? $row['cabang'] ?? '');
                    if (!empty($outletInput)) {
                        if (is_numeric($outletInput)) {
                            $targetOutlet = $businessOutlets->firstWhere('id', (int)$outletInput);
                        }
                        if (!$targetOutlet) {
                            $targetOutlet = $businessOutlets->firstWhere('code', $outletInput);
                        }
                        if (!$targetOutlet) {
                            $targetOutlet = $businessOutlets->first(fn($o) => strcasecmp($o->name, $outletInput) === 0);
                        }
                        if (!$targetOutlet) {
                            $targetOutlet = $businessOutlets->first(fn($o) => str_contains(strtolower($o->name), strtolower($outletInput)));
                        }
                    }
                    if (!$targetOutlet) {
                        $targetOutlet = $mainOutlet;
                    }
                }

                if (!$targetOutlet) {
                    $targetOutlet = $businessOutlets->first() ?? (object)['id' => 1, 'is_main' => true];
                }

                // Calculate Qty in Unit Pakai & Purchase Cost
                $konversi = max((float)$ingredient->konversi, 1);
                $isUnitBeli = ($unitType === 'BELI') ||
                              (!empty($rawUnit) && strcasecmp($rawUnit, $ingredient->unit_beli) === 0 && strcasecmp($ingredient->unit_beli, $ingredient->unit_pakai) !== 0);

                $qtyPakai = $isUnitBeli ? $initialStock * $konversi : $initialStock;
                $hargaBeli = $harga > 0 ? $harga : (float)$ingredient->hargaForOutlet($targetOutlet->id);

                // Update OutletIngredient (isolated per branch)
                $outletRow = OutletIngredient::firstOrNew([
                    'outlet_id'     => $targetOutlet->id,
                    'ingredient_id' => $ingredient->id,
                ]);
                $outletRow->stok_awal = round($qtyPakai, 3);
                if ($minStock !== null) {
                    $outletRow->stok_min = $minStock;
                }
                if ($hargaBeli > 0) {
                    $outletRow->harga = $hargaBeli;
                    $outletRow->last_purchase_price = $hargaBeli;
                }
                $outletRow->save();

                // If this is the main outlet, sync to master Ingredient as well
                $isMain = (bool)($targetOutlet->is_main ?? false) || ((int)$targetOutlet->id === 1);
                if ($isMain) {
                    $ingredient->stok_awal = round($qtyPakai, 3);
                    if ($minStock !== null) {
                        $ingredient->stok_min = $minStock;
                    }
                    if ($hargaBeli > 0) {
                        $ingredient->harga = $hargaBeli;
                        $ingredient->last_purchase_price = $hargaBeli;
                    }
                    $ingredient->save();
                }

                $importedCount++;
            }
        });

        return response()->json([
            'message'        => "Berhasil meng-import {$importedCount} data saldo awal stok persediaan (transisi aplikasi).",
            'imported_count' => $importedCount,
        ]);
    }

    /**
     * Smart Unit Normalizer & Auto-Resolver
     */
    protected function resolveUnit(?string $rawUnit, ?int $businessId, string $default = 'pcs'): array
    {
        $raw = trim($rawUnit ?? '');
        if ($raw === '') {
            $raw = $default;
        }

        $clean = strtolower(preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $raw));
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);

        $aliasMap = [
            'kg' => 'kg', 'kgg' => 'kg', 'kilo' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg', 'kgs' => 'kg',
            'gram' => 'gram', 'gr' => 'gram', 'g' => 'gram', 'gramm' => 'gram', 'grm' => 'gram', 'grams' => 'gram',
            'liter' => 'liter', 'ltr' => 'liter', 'lt' => 'liter', 'liters' => 'liter', 'l' => 'liter',
            'ml' => 'ml', 'mll' => 'ml', 'mililiter' => 'ml', 'milliliter' => 'ml', 'cc' => 'ml',
            'pcs' => 'pcs', 'pc' => 'pcs', 'pcss' => 'pcs', 'piece' => 'pcs', 'pieces' => 'pcs', 
            'buah' => 'pcs', 'biji' => 'pcs', 'butir' => 'pcs', 'btr' => 'pcs',
            'lembar' => 'lembar', 'lbr' => 'lembar', 'sheet' => 'lembar', 'sheets' => 'lembar',
            'slop' => 'slop', 'slp' => 'slop', 'slopp' => 'slop',
            'pack' => 'pack', 'pck' => 'pack', 'pak' => 'pack', 'paket' => 'pack', 'pax' => 'pack',
            'roll' => 'roll', 'rol' => 'roll', 'gulung' => 'roll',
            'botol' => 'botol', 'btl' => 'botol', 'bottle' => 'botol', 'bottles' => 'botol',
            'cup' => 'cup', 'gelas' => 'cup', 'cangkir' => 'cup',
            'dus' => 'dus', 'karton' => 'dus', 'kardus' => 'dus', 'ctn' => 'dus', 'box' => 'dus',
            'can' => 'can', 'kaleng' => 'can', 'klg' => 'can', 'tin' => 'can',
            'sachet' => 'sachet', 'sct' => 'sachet', 'bungkus' => 'sachet', 'bks' => 'sachet',
            'porsi' => 'porsi', 'portion' => 'porsi', 'prs' => 'porsi',
            'sdm' => 'sdm', 'sendok makan' => 'sdm', 'tbsp' => 'sdm',
            'sdt' => 'sdt', 'sendok teh' => 'sdt', 'tsp' => 'sdt',
        ];

        $canonicalSymbol = $aliasMap[$clean] ?? null;

        if (!$canonicalSymbol) {
            $standardSymbols = ['kg', 'gram', 'liter', 'ml', 'pcs', 'lembar', 'slop', 'pack', 'roll', 'botol', 'cup', 'dus', 'can', 'sachet', 'porsi', 'sdm', 'sdt'];
            foreach ($standardSymbols as $sym) {
                if (levenshtein($clean, $sym) <= 1) {
                    $canonicalSymbol = $sym;
                    break;
                }
            }
        }

        if (!$canonicalSymbol) {
            $canonicalSymbol = $clean;
        }

        $nameMap = [
            'kg'     => 'Kilogram',
            'gram'   => 'Gram',
            'liter'  => 'Liter',
            'ml'     => 'Mililiter',
            'pcs'    => 'Pieces',
            'lembar' => 'Lembar',
            'slop'   => 'Slop',
            'pack'   => 'Pack',
            'roll'   => 'Roll',
            'botol'  => 'Botol',
            'cup'    => 'Cup',
            'dus'    => 'Dus / Karton',
            'can'    => 'Kaleng / Can',
            'sachet' => 'Sachet',
            'porsi'  => 'Porsi',
            'sdm'    => 'Sendok Makan',
            'sdt'    => 'Sendok Teh',
        ];

        $unitName = $nameMap[$canonicalSymbol] ?? ucwords(str_replace('_', ' ', $raw));

        $unitQuery = Unit::query();
        if ($businessId) {
            $unitQuery->where(function($q) use ($businessId) {
                $q->where('business_id', $businessId)->orWhereNull('business_id');
            });
        }
        $existingUnit = (clone $unitQuery)->where(function ($q) use ($canonicalSymbol, $unitName) {
            $q->where('symbol', $canonicalSymbol)
              ->orWhere('name', $unitName)
              ->orWhereRaw('LOWER(name) = ?', [strtolower($unitName)])
              ->orWhereRaw('LOWER(symbol) = ?', [strtolower($canonicalSymbol)]);
        })->first();

        if ($existingUnit) {
            return [
                'symbol' => $existingUnit->symbol,
                'name'   => $existingUnit->name,
                'id'     => $existingUnit->id,
            ];
        }

        $newUnit = Unit::create([
            'business_id'  => $businessId,
            'name'         => $unitName,
            'symbol'       => $canonicalSymbol,
            'is_base_unit' => in_array($canonicalSymbol, ['gram', 'ml', 'pcs', 'lembar', 'porsi']),
        ]);

        return [
            'symbol' => $newUnit->symbol,
            'name'   => $newUnit->name,
            'id'     => $newUnit->id,
        ];
    }
}

