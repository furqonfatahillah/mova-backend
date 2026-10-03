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
use App\Models\UrgentNote;
use App\Models\WasteLog;
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

        // Shift filtering
        if ($request->filled('shift_id') && strtoupper($request->shift_id) !== 'ALL') {
            $shiftInput = $request->shift_id;
            if (is_array($shiftInput)) {
                $shiftIds = array_values(array_filter(array_map('intval', $shiftInput)));
                if (count($shiftIds) > 0) {
                    $query->whereIn('shift_id', $shiftIds);
                }
            } else {
                $shiftIds = array_values(array_filter(array_map('intval', explode(',', $shiftInput))));
                if (count($shiftIds) > 0) {
                    $query->whereIn('shift_id', $shiftIds);
                }
            }
        }

        if ($request->filled('payment_type') && strtoupper($request->payment_type) !== 'ALL') {
            $rawPt = $request->payment_type;
            $ptList = is_array($rawPt) ? $rawPt : explode(',', $rawPt);
            $ptList = array_map(fn($v) => strtoupper(trim($v)), array_filter($ptList));

            if (count($ptList) > 0 && !in_array('ALL', $ptList)) {
                $query->where(function($q) use ($ptList) {
                    foreach ($ptList as $idx => $pt) {
                        $clause = function($subQ) use ($pt) {
                            if ($pt === 'CASH_ALL' || $pt === 'CASH_AND_PETTY' || $pt === 'CASH' || $pt === 'TUNAI' || $pt === 'PETTY_CASH') {
                                $subQ->where(function($inner) {
                                    $inner->whereIn('payment_type', ['CASH', 'TUNAI', 'PETTY_CASH'])
                                          ->orWhereNull('payment_type')
                                          ->orWhere('payment_type', 'like', '%TUNAI%')
                                          ->orWhere('payment_type', 'like', '%CASH%')
                                          ->orWhere('payment_type', 'like', '%PETTY%');
                                });
                            } elseif ($pt === 'NON_CASH' || $pt === 'ALL_NON_CASH') {
                                $subQ->where(function($inner) {
                                    $inner->where('payment_type', 'like', '%TRANSFER%')
                                          ->orWhere('payment_type', 'like', '%QRIS%')
                                          ->orWhere('payment_type', 'like', '%DEBIT%');
                                });
                            } elseif ($pt === 'TRANSFER') {
                                $subQ->where('payment_type', 'like', '%TRANSFER%');
                            } elseif ($pt === 'PETTY_CASH') {
                                $subQ->where('payment_type', 'PETTY_CASH');
                            } elseif ($pt === 'QRIS') {
                                $subQ->where('payment_type', 'like', '%QRIS%');
                            } else {
                                $subQ->where('payment_type', $pt);
                            }
                        };

                        if ($idx === 0) {
                            $q->where($clause);
                        } else {
                            $q->orWhere($clause);
                        }
                    }
                });
            }
        }

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

        $isUnitBeli = (($request->unit_type ?? 'BELI') === 'BELI');
        $qtyPakai = $isUnitBeli ? (float)$data['qty'] * $konversi : (float)$data['qty'];

        $costBefore = $ingredient->costPerPakaiForOutlet($outletId);
        $costAfter  = $costBefore;
        $sourcePricePerBeli = $ingredient->hargaForOutlet($outletId);
        $unitPrice  = $isUnitBeli ? $sourcePricePerBeli : $costBefore;
        $totalPrice = round(($qtyPakai / $konversi) * $sourcePricePerBeli, 2);

        $targetOutlet = Outlet::find($outletId);
        $isHolding = $targetOutlet ? (bool)$targetOutlet->is_main : ($outletId == 1);

        $rawPaymentType = strtoupper(trim($request->payment_type ?? 'CASH'));
        if (!$isHolding) {
            // Outlet Cabang: Pembelian Kas Only (Petty Cash Cabang)
            $paymentType = 'CASH';
            $isHutang = false;
        } else {
            // Holding: Hutang, Kas, dan Bank
            $isHutang = in_array($rawPaymentType, ['HUTANG', 'TEMPO']);
            $paymentType = $isHutang ? 'HUTANG' : (in_array($rawPaymentType, ['BANK', 'TRANSFER', 'QRIS']) ? 'BANK' : 'CASH');
        }
        $payableId = null;

        if ($data['type'] === 'PURCHASE') {
            $currentStock = $ingredient->stockForOutlet($outletId);
            if ($currentStock < -0.0001) {
                return response()->json([
                    'message' => "Stok bahan baku '{$ingredient->name}' di cabang ini saat ini berstatus MINUS (" . round($currentStock, 3) . " {$ingredient->unit_pakai}). Anda harus melakukan Penyesuaian Stok (Adjust Stock / Opname) terlebih dahulu untuk menormalkan saldo minus sebelum mencatat transaksi pembelian baru.",
                    'error_code' => 'STOCK_NEGATIVE_ADJUSTMENT_REQUIRED',
                    'ingredient_id' => $ingredient->id,
                    'ingredient_name' => $ingredient->name,
                    'current_stock' => $currentStock,
                    'unit' => $ingredient->unit_pakai,
                ], 422);
            }

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
        $targetOutlet = Outlet::find($outletId);
        $businessId = $user?->business_id ?? $targetOutlet?->business_id;
        $isHolding = $targetOutlet ? (bool)$targetOutlet->is_main : ($outletId == 1);

        $rawPaymentType = strtoupper(trim($validated['payment_type'] ?? 'CASH'));
        if (!$isHolding) {
            // Outlet Cabang: Pembelian Kas Only (Petty Cash Cabang)
            $paymentType = 'CASH';
            $isHutang = false;
        } else {
            // Holding: Hutang, Kas, dan Bank
            $isHutang = in_array($rawPaymentType, ['HUTANG', 'TEMPO']);
            $paymentType = $isHutang ? 'HUTANG' : (in_array($rawPaymentType, ['BANK', 'TRANSFER', 'QRIS']) ? $rawPaymentType : 'CASH');
        }

        if ($validated['type'] === 'PURCHASE') {
            $negativeItems = [];
            foreach ($validated['items'] as $item) {
                $ing = Ingredient::find($item['ingredient_id']);
                if ($ing) {
                    $cStock = $ing->stockForOutlet($outletId);
                    if ($cStock < -0.0001) {
                        $negativeItems[] = "{$ing->name} (Stok: " . round($cStock, 3) . " {$ing->unit_pakai})";
                    }
                }
            }
            if (!empty($negativeItems)) {
                return response()->json([
                    'message' => "Terdapat bahan baku dengan stok MINUS: " . implode(', ', $negativeItems) . ". Anda harus melakukan Penyesuaian Stok (Adjust Stock / Opname) terlebih dahulu kepada bahan tersebut sebelum mencatat transaksi pembelian baru.",
                    'error_code' => 'STOCK_NEGATIVE_ADJUSTMENT_REQUIRED',
                    'negative_items' => $negativeItems,
                ], 422);
            }
        }

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
                $sourcePricePerBeli = $ingredient->hargaForOutlet($outletId);
                $unitPrice = $isUnitBeli ? $sourcePricePerBeli : $costBefore;
                $totalPrice = round(($qtyPakai / $konversi) * $sourcePricePerBeli, 2);

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
            ->where('type', '!=', 'INITIAL')
            ->where('date', '<', $from)
            ->selectRaw("ingredient_id, SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as prior_sum")
            ->groupBy('ingredient_id')
            ->pluck('prior_sum', 'ingredient_id')
            ->all();

        // 2. Group movements between $from and $to for this outlet
        $periodMovements = StockMovement::whereIn('ingredient_id', $ingIds)
            ->where('outlet_id', $outletId)
            ->where('type', '!=', 'INITIAL')
            ->whereBetween('date', [$from, $to])
            ->selectRaw("
                ingredient_id,
                SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE 0 END) as total_masuk,
                SUM(CASE WHEN type IN ('SALE_USAGE','WASTE','TRANSFER_OUT','ADJUSTMENT_OUT','PREP_USAGE') THEN qty ELSE 0 END) as total_keluar,
                SUM(CASE WHEN type = 'SALE_USAGE' THEN qty ELSE 0 END) as total_penjualan,
                SUM(CASE WHEN type = 'WASTE' THEN qty ELSE 0 END) as total_waste,
                SUM(CASE WHEN type = 'PURCHASE' THEN qty ELSE 0 END) as total_pembelian
            ")
            ->groupBy('ingredient_id')
            ->get()
            ->keyBy('ingredient_id');

        // Fetch urgent note summaries for this outlet to detect pending and resolved stock deficits
        $urgentQuery = UrgentNote::where('business_id', $outlet->business_id ?: 1);
        if ($outletId) {
            $urgentQuery->where('outlet_id', $outletId);
        }
        $allUrgentNotes = $urgentQuery->get();
        $urgentIngIds = $allUrgentNotes->whereNotNull('ingredient_id')->pluck('ingredient_id')->unique()->all();
        $pendingUrgentMap = $allUrgentNotes->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
            ->groupBy('ingredient_id')
            ->map(fn($group) => $group->sum('pending_qty'))
            ->all();
        $resolvedUrgentMap = $allUrgentNotes->where('status', 'RESOLVED')
            ->groupBy('ingredient_id')
            ->map(fn($group) => $group->sum('pending_qty'))
            ->all();

        // Calculate global period movement metrics (HPP, Transfers, Purchases, Waste)
        $periodMovementsRaw = StockMovement::whereIn('ingredient_id', $ingIds)
            ->where('outlet_id', $outletId)
            ->where('type', '!=', 'INITIAL')
            ->whereBetween('date', [$from, $to])
            ->get(['id', 'ingredient_id', 'type', 'qty', 'unit_price', 'total_price', 'cost_before', 'cost_after']);

        $ingLookup = $ingredients->keyBy('id');
        $totalHppRp = 0.0;
        $totalTransferInRp = 0.0;
        $totalTransferInQty = 0.0;
        $totalTransferInCount = 0;
        $totalTransferOutRp = 0.0;
        $totalTransferOutQty = 0.0;
        $totalTransferOutCount = 0;
        $totalPembelianRp = 0.0;
        $totalWasteRp = 0.0;
        $totalWasteQty = 0.0;
        $totalWasteCount = 0;

        $nominalMasukByIng = [];
        $nominalKeluarByIng = [];
        $inTypes  = ['PURCHASE', 'ADJUSTMENT_IN', 'TRANSFER_IN', 'PREP_OUTPUT', 'ADJUSTMENT_PLUS'];
        $outTypes = ['SALE_USAGE', 'WASTE', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'PREP_USAGE'];

        foreach ($periodMovementsRaw as $m) {
            $ing = $ingLookup->get($m->ingredient_id);
            $konversi = $ing ? max((float)$ing->konversi, 1) : 1;
            $hargaBeliOutlet = $ing ? (float)$ing->hargaForOutlet($outletId) : 0;
            $costPerPakai = $hargaBeliOutlet / $konversi;
            $qty = (float)$m->qty;
            $unitPriceMov = (float)($m->unit_price > 0 ? ($m->unit_price > 1000 && $konversi > 1 ? $m->unit_price / $konversi : $m->unit_price) : ($m->cost_after > 0 ? $m->cost_after : ($m->cost_before > 0 ? $m->cost_before : $costPerPakai)));
            $val = $m->total_price > 0 ? (float)$m->total_price : ($qty * $unitPriceMov);

            if (in_array($m->type, $inTypes)) {
                $nominalMasukByIng[$m->ingredient_id] = ($nominalMasukByIng[$m->ingredient_id] ?? 0.0) + $val;
            } elseif (in_array($m->type, $outTypes)) {
                $nominalKeluarByIng[$m->ingredient_id] = ($nominalKeluarByIng[$m->ingredient_id] ?? 0.0) + $val;
            }

            if ($m->type === 'SALE_USAGE' || $m->type === 'PREP_USAGE') {
                $totalHppRp += $val;
            } elseif ($m->type === 'TRANSFER_IN') {
                $totalTransferInRp += $val;
                $totalTransferInQty += $qty;
                $totalTransferInCount++;
            } elseif ($m->type === 'TRANSFER_OUT') {
                $totalTransferOutRp += $val;
                $totalTransferOutQty += $qty;
                $totalTransferOutCount++;
            } elseif ($m->type === 'PURCHASE') {
                $totalPembelianRp += $val;
            } elseif ($m->type === 'WASTE') {
                $totalWasteRp += $val;
                $totalWasteQty += $qty;
                $totalWasteCount++;
            }
        }

        // Include any menu waste logs or direct waste logs not linked to a movement
        $menuWasteLogs = WasteLog::whereBetween('date', [$from, $to])
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->whereNull('stock_movement_id')
            ->get();

        foreach ($menuWasteLogs as $mw) {
            $totalWasteRp += (float)$mw->loss_cost;
            $totalWasteQty += (float)$mw->qty_pakai;
            $totalWasteCount++;
        }

        $items = [];
        $grandTotalSafeValuation = 0.0;
        $grandTotalDeficitValuation = 0.0;
        $totalSafeStock = 0;
        $totalNegativeStock = 0;
        $totalEmptyStock = 0;
        $totalLowStock = 0;

        $isHoldingOutlet = (bool)$outlet->is_main || ((int)$outletId === 1);

        foreach ($ingredients as $ing) {
            $outletRow = $ing->outletIngredients->first();
            $stokAwalMaster = 0.0;
            $saldoAwalNominalMaster = 0.0;
            if ($outletRow && (float)$outletRow->stok_awal > 0) {
                $stokAwalMaster = (float)$outletRow->stok_awal;
                $saldoAwalNominalMaster = (float)($outletRow->saldo_awal_nominal ?? 0);
            } elseif ($isHoldingOutlet) {
                $stokAwalMaster = (float)$ing->stok_awal;
                $saldoAwalNominalMaster = (float)($ing->saldo_awal_nominal ?? 0);
            }
            $stokMinOutlet  = $outletRow && $outletRow->stok_min !== null ? (float) $outletRow->stok_min : (float) $ing->stok_min;

            $hargaBeliOutlet = $ing->hargaForOutlet($outletId);
            $hargaPerPakai = $hargaBeliOutlet / max($ing->konversi, 1);

            if ($saldoAwalNominalMaster <= 0 && $stokAwalMaster > 0) {
                $saldoAwalNominalMaster = round($stokAwalMaster * $hargaPerPakai, 2);
            }

            $priorSum = isset($priorMovements[$ing->id]) ? (float)$priorMovements[$ing->id] : 0.0;
            $stokAwalPeriod = round($stokAwalMaster + $priorSum, 3);

            $periodData = $periodMovements->get($ing->id);
            $totalMasuk = $periodData ? (float)$periodData->total_masuk : 0.0;
            $totalKeluar = $periodData ? (float)$periodData->total_keluar : 0.0;
            $totalPenjualan = $periodData ? (float)$periodData->total_penjualan : 0.0;
            $totalWaste = $periodData ? (float)$periodData->total_waste : 0.0;
            $totalPembelian = $periodData ? (float)$periodData->total_pembelian : 0.0;

            $stokAkhir = round($stokAwalPeriod + $totalMasuk - $totalKeluar, 3);
            if ($priorSum == 0 && $totalMasuk == 0 && $totalKeluar == 0 && $saldoAwalNominalMaster > 0) {
                $nilaiStok = $saldoAwalNominalMaster;
            } else {
                $nilaiStok = round($stokAkhir * $hargaPerPakai, 2);
            }
            if (abs($nilaiStok - round($nilaiStok)) < 0.05) {
                $nilaiStok = (float)round($nilaiStok);
            }

            $nominalMasuk = round($nominalMasukByIng[$ing->id] ?? 0.0, 2);
            $nominalKeluar = round($nominalKeluarByIng[$ing->id] ?? 0.0, 2);
            if ($nominalMasuk <= 0 && $totalMasuk > 0) {
                $nominalMasuk = round($totalMasuk * $hargaPerPakai, 2);
            }
            if ($nominalKeluar <= 0 && $totalKeluar > 0) {
                $nominalKeluar = round($totalKeluar * $hargaPerPakai, 2);
            }

            $nilaiSaldoAwal = ($priorSum == 0 && $saldoAwalNominalMaster > 0)
                ? $saldoAwalNominalMaster
                : round($stokAwalPeriod * $hargaPerPakai, 2);
            if (abs($nilaiSaldoAwal - round($nilaiSaldoAwal)) < 0.05) {
                $nilaiSaldoAwal = (float)round($nilaiSaldoAwal);
            }

            $saldoAmanRp = $stokAkhir > 0 ? $nilaiStok : 0.0;
            $saldoMinusRp = $stokAkhir < 0 ? $nilaiStok : 0.0; // Negative Rupiah value

            $isNegative = $stokAkhir < 0;
            $isSafe = $stokAkhir > 0;
            $isEmpty = ($stokAkhir == 0);
            $isLow = ($stokAkhir <= $stokMinOutlet && $stokAkhir >= 0);

            if ($isNegative) {
                $totalNegativeStock++;
                $grandTotalDeficitValuation += $saldoMinusRp; // negative accumulation
            } elseif ($isSafe) {
                $totalSafeStock++;
                $grandTotalSafeValuation += $saldoAmanRp;
            } else {
                $totalEmptyStock++;
            }

            if ($isLow) {
                $totalLowStock++;
            }

            $outletRow = $ing->outletIngredients->first();
            $tanggalSaldoAwalItem = $outletRow?->tanggal_saldo_awal ?? $ing->tanggal_saldo_awal;

            $items[] = [
                'id'                     => $ing->id,
                'code'                   => $ing->code,
                'name'                   => $ing->name,
                'category'               => $ing->category,
                'type'                   => $ing->type ?? 'RAW',
                'unit_pakai'             => $ing->unit_pakai,
                'unit_beli'              => $ing->unit_beli,
                'konversi'               => $ing->konversi,
                'harga_beli'             => (float)$hargaBeliOutlet,
                'harga_satuan'           => round($hargaPerPakai, 2),
                'stok_min'               => $stokMinOutlet,
                'stok_awal'              => $stokAwalPeriod,
                'nilai_saldo_awal'       => $nilaiSaldoAwal,
                'tanggal_saldo_awal'     => $tanggalSaldoAwalItem,
                'total_masuk'            => $totalMasuk,
                'total_keluar'           => $totalKeluar,
                'nominal_masuk'          => $nominalMasuk,
                'nominal_keluar'         => $nominalKeluar,
                'total_penjualan'        => $totalPenjualan,
                'total_waste'            => $totalWaste,
                'total_pembelian'        => $totalPembelian,
                'stok_akhir'             => $stokAkhir,
                'is_low'                 => $isLow,
                'is_empty'               => $isEmpty,
                'is_negative'            => $isNegative,
                'is_safe'                => $isSafe,
                'nilai_stok'             => $nilaiStok, // Negative value when stock is minus
                'saldo_aman_rp'          => $saldoAmanRp,
                'saldo_minus_rp'         => $saldoMinusRp,
                'has_urgent_note'        => in_array($ing->id, $urgentIngIds),
                'pending_urgent_qty'     => (float)($pendingUrgentMap[$ing->id] ?? 0),
                'pending_urgent_nominal' => round((float)($pendingUrgentMap[$ing->id] ?? 0) * $hargaPerPakai, 2),
                'resolved_urgent_qty'    => (float)($resolvedUrgentMap[$ing->id] ?? 0),
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
                'total_items'              => count($items),
                'total_safe_items'         => $totalSafeStock,
                'total_negative_items'     => $totalNegativeStock,
                'total_empty_items'        => $totalEmptyStock,
                'total_low_stock'          => $totalLowStock,
                'total_safe_valuation'     => round($grandTotalSafeValuation),
                'total_deficit_valuation'  => round($grandTotalDeficitValuation),
                'net_total_valuation'      => round($grandTotalSafeValuation + $grandTotalDeficitValuation),
                'total_nilai'              => round($grandTotalSafeValuation),
                'total_hpp_rp'             => round($totalHppRp),
                'total_transfer_in_rp'     => round($totalTransferInRp),
                'total_transfer_in_qty'    => round($totalTransferInQty, 2),
                'total_transfer_in_count'  => $totalTransferInCount,
                'total_transfer_out_rp'    => round($totalTransferOutRp),
                'total_transfer_out_qty'   => round($totalTransferOutQty, 2),
                'total_transfer_out_count' => $totalTransferOutCount,
                'total_pembelian_rp'       => round($totalPembelianRp),
                'total_waste_rp'           => round($totalWasteRp),
                'total_waste_qty'          => round($totalWasteQty, 2),
                'total_waste_count'        => $totalWasteCount,
                'total_nilai_saldo_awal_rp'=> round(array_sum(array_column($items, 'nilai_saldo_awal')), 2),
                'total_nominal_masuk_rp'   => round(array_sum(array_column($items, 'nominal_masuk')), 2),
                'total_nominal_keluar_rp'  => round(array_sum(array_column($items, 'nominal_keluar')), 2),
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
        $tanggalSaldoAwal = null;
        if ($outletId) {
            $ot = \App\Models\Outlet::find($outletId);
            $isHolding = $ot ? (bool)$ot->is_main : ((int)$outletId === 1);
            $outletRow = $ingredient->outletIngredients->firstWhere('outlet_id', $outletId);
            $stokAwalMaster = 0.0;
            $saldoAwalNominalMaster = 0.0;
            if ($outletRow && (float)$outletRow->stok_awal > 0) {
                $stokAwalMaster = (float)$outletRow->stok_awal;
                $saldoAwalNominalMaster = (float)($outletRow->saldo_awal_nominal ?? 0);
                $tanggalSaldoAwal = $outletRow->tanggal_saldo_awal;
            } elseif ($isHolding) {
                $stokAwalMaster = (float)$ingredient->stok_awal;
                $saldoAwalNominalMaster = (float)($ingredient->saldo_awal_nominal ?? 0);
                $tanggalSaldoAwal = $ingredient->tanggal_saldo_awal;
            }
            if (!$tanggalSaldoAwal && $outletRow) {
                $tanggalSaldoAwal = $outletRow->tanggal_saldo_awal;
            }
            if (!$tanggalSaldoAwal) {
                $tanggalSaldoAwal = $ingredient->tanggal_saldo_awal;
            }
            $stokMinOutlet  = $outletRow && $outletRow->stok_min !== null ? (float) $outletRow->stok_min : (float) $ingredient->stok_min;
        } else {
            // Consolidated across all branches
            $sumInit = (float) $ingredient->outletIngredients->sum('stok_awal');
            $stokAwalMaster = $sumInit > 0 ? $sumInit : (float) $ingredient->stok_awal;
            $saldoAwalNominalMaster = (float) $ingredient->outletIngredients->sum('saldo_awal_nominal');
            if ($saldoAwalNominalMaster <= 0) {
                $saldoAwalNominalMaster = (float) $ingredient->saldo_awal_nominal;
            }
            $firstOutRow = $ingredient->outletIngredients->firstWhere('tanggal_saldo_awal', '!=', null);
            $tanggalSaldoAwal = $firstOutRow?->tanggal_saldo_awal ?? $ingredient->tanggal_saldo_awal;
            $stokMinOutlet  = (float) $ingredient->stok_min;
        }

        $konversi = max((float)$ingredient->konversi, 1);
        $initialHarga = $outletRow && $outletRow->harga !== null ? (float)$outletRow->harga : (float)$ingredient->harga;
        $initialCostPerPakai = $initialHarga / $konversi;

        if ($saldoAwalNominalMaster <= 0 && $stokAwalMaster > 0) {
            $saldoAwalNominalMaster = round($stokAwalMaster * $initialCostPerPakai, 2);
        }

        $outTypes = ['SALE_USAGE', 'WASTE', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'PREP_USAGE'];
        $inTypes  = ['PURCHASE', 'ADJUSTMENT_IN', 'TRANSFER_IN', 'PREP_OUTPUT', 'ADJUSTMENT_PLUS'];

        // 1. Calculate historical opening stock & nominal balance strictly before $from (SUM dari saldo awal & mutasi sebelumnya)
        $runningStock = $stokAwalMaster;
        $runningNominal = $saldoAwalNominalMaster;
        $currentCostPerPakai = $stokAwalMaster > 0 ? ($saldoAwalNominalMaster / $stokAwalMaster) : $initialCostPerPakai;

        $priorMovements = StockMovement::where('ingredient_id', $ingId)
            ->where('type', '!=', 'INITIAL')
            ->where('date', '<', $from)
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($priorMovements as $m) {
            $qty = (float)$m->qty;
            if (in_array($m->type, $inTypes)) {
                if ($m->total_price > 0 && $qty > 0) {
                    $inVal = (float)$m->total_price;
                } elseif ($m->unit_price > 0) {
                    $inVal = $qty * ((float)$m->unit_price / $konversi);
                } else {
                    $inVal = $qty * $currentCostPerPakai;
                }
                $runningStock += $qty;
                $runningNominal += $inVal;
                if ($runningStock > 0) {
                    $currentCostPerPakai = $runningNominal / $runningStock;
                }
            } else {
                $unitCost = $currentCostPerPakai;
                $outVal = ($m->type === 'TRANSFER_OUT' && $m->total_price > 0) ? (float)$m->total_price : ($qty * $unitCost);
                $runningStock -= $qty;
                $runningNominal -= $outVal;
            }
        }

        $stokAwal = round($runningStock, 3);
        $nilaiSaldoAwal = round($runningNominal, 2);
        if (abs($nilaiSaldoAwal - round($nilaiSaldoAwal)) < 0.05) {
            $nilaiSaldoAwal = (float)round($nilaiSaldoAwal);
        }
        $costAwalPerPakai = $stokAwal != 0 ? round($nilaiSaldoAwal / $stokAwal, 2) : round($currentCostPerPakai, 2);

        // Movements in the period ordered chronologically
        $movQuery = StockMovement::with([
            'user', 'creator', 'updater', 'outlet',
            'transaction.menu', 'shift.user', 'shift.closedByUser',
            'transfer.destinationOutlet', 'transfer.sourceOutlet'
        ])
            ->where('ingredient_id', $ingId)
            ->where('type', '!=', 'INITIAL')
            ->whereBetween('date', [$from, $to]);

        if ($outletId) {
            $movQuery->where('outlet_id', $outletId);
        }

        $movements = $movQuery->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $running = $stokAwal;
        $runningNominal = $nilaiSaldoAwal;
        $currentCostPerPakai = $costAwalPerPakai;
        $totalMasuk = 0;
        $totalKeluar = 0;
        $totalPenjualan = 0;
        $totalWaste = 0;
        $totalWasteNominal = 0.0;
        $totalPembelian = 0;

        $rows = [];
        foreach ($movements as $m) {
            $isOut = in_array($m->type, $outTypes);
            $qty = (float)$m->qty;
            $qtyIn = $isOut ? 0 : $qty;
            $qtyOut = $isOut ? $qty : 0;
            $costBeforeRow = $currentCostPerPakai;

            if ($isOut) {
                $totalKeluar += $qtyOut;
                $running -= $qtyOut;
                if ($m->type === 'SALE_USAGE') $totalPenjualan += $qtyOut;

                $unitCost = $currentCostPerPakai;
                $outVal = ($m->type === 'TRANSFER_OUT' && $m->total_price > 0) ? (float)$m->total_price : ($qtyOut * $unitCost);
                if ($m->type === 'WASTE') {
                    $totalWaste += $qtyOut;
                    $totalWasteNominal += ($m->total_price > 0 ? (float)$m->total_price : $outVal);
                }
                $runningNominal -= $outVal;
                $costAfterRow = $currentCostPerPakai;
                $inVal = 0.0;
            } else {
                $totalMasuk += $qtyIn;
                $running += $qtyIn;
                if ($m->type === 'PURCHASE')   $totalPembelian += $qtyIn;

                if ($m->total_price > 0 && $qtyIn > 0) {
                    $inVal = (float)$m->total_price;
                } elseif ($m->unit_price > 0) {
                    $inVal = $qtyIn * ((float)$m->unit_price / $konversi);
                } else {
                    $inVal = $qtyIn * $currentCostPerPakai;
                }
                $runningNominal += $inVal;
                $costAfterRow = $running > 0 ? ($runningNominal / $running) : $currentCostPerPakai;
                $currentCostPerPakai = $costAfterRow;
                $outVal = 0.0;
            }

            $ref = "MVT-{$m->id}";
            if ($m->transfer_id && $m->transfer) {
                $ref = $m->transfer->transfer_no;
            } elseif ($m->shift_id) {
                $ref = $m->shift?->shift_name ?: "Shift {$m->shift_id}";
            } elseif ($m->transaction_id) {
                $ref = "TRX-{$m->transaction_id}";
            } elseif ($m->note) {
                $ref = $m->note;
            }

            $creatorName = $m->creator?->name ?? ($m->user?->name ?? ($m->shift?->user?->name ?? 'Sistem'));
            $updaterName = $m->updater?->name ?? ($m->shift?->closedByUser?->name ?? null);
            $hasChanged = ($m->updated_at && $m->created_at && $m->updated_at->ne($m->created_at)) || !empty($updaterName);

            $isUrgentResolution = str_contains($m->note ?? '', 'Pelunasan Nota Urgent')
                || str_contains($m->note ?? '', 'Pelunasan Bahan Tergantung')
                || str_contains($m->note ?? '', 'Nota Urgent');

            $balanceNominal = round($runningNominal, 2);
            if (abs($balanceNominal - round($balanceNominal)) < 0.05) {
                $balanceNominal = (float)round($balanceNominal);
            }

            $rows[] = [
                'id'                   => $m->id,
                'date'                 => $m->date,
                'created_at'           => $m->created_at?->format('Y-m-d H:i:s'),
                'created_time'         => $m->created_at?->format('H:i:s'),
                'created_by'           => $m->created_by,
                'created_by_name'      => $creatorName,
                'changed_at'           => $hasChanged ? $m->updated_at?->format('Y-m-d H:i:s') : null,
                'changed_by'           => $m->updated_by,
                'changed_by_name'      => $hasChanged ? $updaterName : null,
                'outlet_id'            => $m->outlet_id,
                'ingredient_id'        => $m->ingredient_id,
                'transfer_id'          => $m->transfer_id,
                'outlet_name'          => $m->outlet?->name ?? ($m->outlet_id ? "Outlet #{$m->outlet_id}" : '-'),
                'type'                 => $m->type,
                'waste_reason'         => $m->waste_reason,
                'note'                 => $m->note,
                'ref'                  => $ref,
                'shift_id'             => $m->shift_id,
                'shift_name'           => $m->shift?->shift_name,
                'transaction_id'       => $m->transaction_id,
                'qty_in'               => $qtyIn,
                'qty_out'              => $qtyOut,
                'unit_price'           => $m->unit_price,
                'total_price'          => $m->total_price,
                'in_nominal'           => round($inVal, 2),
                'out_nominal'          => round($outVal, 2),
                'cost_before'          => round($costBeforeRow, 2),
                'cost_after'           => round($costAfterRow, 2),
                'cost_per_pakai'       => round($costAfterRow, 2),
                'balance'              => round($running, 3),
                'balance_nominal'      => $balanceNominal,
                'is_negative'          => $running < 0,
                'is_urgent_resolution' => $isUrgentResolution,
                'user'                 => $creatorName,
                'payment_type'         => $m->payment_type ?? 'CASH',
                'supplier_name'        => $m->supplier_name,
                'purchase_no'          => $m->purchase_no,
                'payable_id'           => $m->payable_id,
            ];
        }

        $stokAkhir = round($running, 3);
        $outletObj = $outletId ? \App\Models\Outlet::find($outletId) : null;
        $isNegative = $stokAkhir < 0;
        $nilaiStokAkhir = round($runningNominal, 2);
        if (abs($nilaiStokAkhir - round($nilaiStokAkhir)) < 0.05) {
            $nilaiStokAkhir = (float)round($nilaiStokAkhir);
        }
        $saldoAmanRp = $stokAkhir > 0 ? $nilaiStokAkhir : 0.0;
        $saldoMinusRp = $stokAkhir < 0 ? $nilaiStokAkhir : 0.0;

        return response()->json([
            'ingredient'           => $ingredient,
            'period'               => ['from' => $from, 'to' => $to],
            'outlet_id'            => $outletId,
            'outlet_name'          => $outletObj ? $outletObj->name : 'Semua Cabang (Konsolidasi)',
            'tanggal_saldo_awal'   => $tanggalSaldoAwal,
            'stok_awal'            => round($stokAwal, 3),
            'nilai_saldo_awal'     => $nilaiSaldoAwal,
            'cost_awal_per_pakai'  => $costAwalPerPakai,
            'total_masuk'          => round($totalMasuk, 3),
            'total_keluar'         => round($totalKeluar, 3),
            'total_pembelian'      => round($totalPembelian, 3),
            'total_penjualan'      => round($totalPenjualan, 3),
            'total_waste'          => round($totalWaste, 3),
            'total_waste_nominal'  => round($totalWasteNominal, 2),
            'stok_akhir'           => $stokAkhir,
            'stok_min'             => $stokMinOutlet,
            'is_below_min'         => $stokAkhir < $stokMinOutlet,
            'is_negative'          => $isNegative,
            'nilai_stok_akhir'     => $nilaiStokAkhir,
            'saldo_aman_rp'        => $saldoAmanRp,
            'saldo_minus_rp'       => $saldoMinusRp,
            'has_urgent_movements' => $movements->contains(fn($m) => str_contains($m->note ?? '', 'Nota Urgent')),
            'rows'                 => $rows,
        ]);
    }

    /**
     * Bulk Import Initial Stock per Warehouse / Branch (Stock Awal Fisik Per Gudang / Cabang)
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
        if ($businessOutlets->isEmpty()) {
            $businessOutlets = Outlet::all();
        }
        $mainOutlet = $businessOutlets->firstWhere('is_main', true) ?? $businessOutlets->first();

        // If user is restricted to a specific outlet
        $isOutletBounded = $user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id;
        $globalEffectiveDate = $request->input('effective_date') ?? $request->input('date');

        try {
            DB::transaction(function () use ($items, $businessId, $user, $businessOutlets, $mainOutlet, $isOutletBounded, $globalEffectiveDate, &$importedCount) {
                foreach ($items as $row) {
                if (empty($row['name'])) continue;

                $name = trim($row['name']);
                $lowerName = strtolower($name);

                if (
                    str_starts_with($name, '===') ||
                    str_contains($lowerName, 'template import') ||
                    str_contains($lowerName, 'petunjuk') ||
                    str_contains($lowerName, 'saldo awal stok') ||
                    str_contains($lowerName, 'stock awal fisik') ||
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

                $rawUnit = trim($row['unit'] ?? $row['satuan'] ?? '');
                $unitType = strtoupper(trim($row['unit_type'] ?? 'PAKAI'));
                $initialStock = (float)($row['initial_stock'] ?? $row['stok_awal'] ?? $row['qty'] ?? 0);
                $totalVal = (float)($row['total_nilai'] ?? $row['total_saldo_awal'] ?? $row['total_price'] ?? $row['total'] ?? 0);
                $hargaInput = (float)($row['harga'] ?? $row['price'] ?? $row['harga_beli'] ?? $row['unit_price'] ?? 0);
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

                    $catName = !empty($row['category']) ? trim($row['category']) : ($isPerlengkapan ? 'Perlengkapan' : 'BAHAN_BAKU');
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

                    $isUnitBeliTemp = ($unitType === 'BELI') || (!empty($rawUnit) && strcasecmp($rawUnit, $uBeliInfo['symbol']) === 0 && strcasecmp($uBeliInfo['symbol'], $uPakaiInfo['symbol']) !== 0);
                    $qtyPakaiTemp = $isUnitBeliTemp ? $initialStock * $konversi : $initialStock;
                    $calcHargaBeli = 0;
                    if ($totalVal > 0 && $qtyPakaiTemp > 0) {
                        $calcPricePerPakai = $totalVal / $qtyPakaiTemp;
                        $calcHargaBeli = round($calcPricePerPakai * $konversi, 2);
                    } elseif ($hargaInput > 0) {
                        $calcHargaBeli = $hargaInput;
                    }

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
                        'harga'         => $calcHargaBeli,
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

                // Calculate Qty in Unit Pakai & Purchase Cost / Moving Average Cost
                $konversi = max((float)$ingredient->konversi, 1);
                $isUnitBeli = ($unitType === 'BELI') ||
                              (!empty($rawUnit) && strcasecmp($rawUnit, $ingredient->unit_beli) === 0 && strcasecmp($ingredient->unit_beli, $ingredient->unit_pakai) !== 0);

                $qtyPakai = $isUnitBeli ? $initialStock * $konversi : $initialStock;

                if ($totalVal > 0 && $qtyPakai > 0) {
                    // Moving average unit price calculation: Total Nilai Saldo Awal / Qty
                    $pricePerPakai = $totalVal / $qtyPakai;
                    $hargaBeli = round($pricePerPakai * $konversi, 2);
                    $totalPriceVal = $totalVal;
                } else {
                    $hargaBeli = $hargaInput > 0 ? $hargaInput : (float)$ingredient->hargaForOutlet($targetOutlet->id);
                    $pricePerPakai = $hargaBeli / $konversi;
                    $totalPriceVal = round(($qtyPakai / $konversi) * $hargaBeli, 2);
                }

                // Parse Effective Date (Tanggal Mulai Saldo Awal)
                $rawDate = $row['date'] ?? $row['tanggal_mulai'] ?? $row['tanggal_efektif'] ?? $row['effective_date'] ?? $row['tanggal'] ?? $globalEffectiveDate ?? null;
                $effectiveDate = Carbon::today()->toDateString();
                if (!empty($rawDate)) {
                    try {
                        if (is_numeric($rawDate) && (float)$rawDate > 20000 && (float)$rawDate < 80000) {
                            $effectiveDate = Carbon::createFromTimestampUTC(((float)$rawDate - 25569) * 86400)->toDateString();
                        } else {
                            $effectiveDate = Carbon::parse($rawDate)->toDateString();
                        }
                    } catch (\Throwable $e) {
                        $effectiveDate = Carbon::today()->toDateString();
                    }
                }

                $notes = !empty($row['notes']) ? trim($row['notes']) : (!empty($row['catatan']) ? trim($row['catatan']) : (!empty($row['keterangan']) ? trim($row['keterangan']) : null));

                // Update OutletIngredient (isolated per branch)
                $outletRow = OutletIngredient::firstOrNew([
                    'outlet_id'     => $targetOutlet->id,
                    'ingredient_id' => $ingredient->id,
                ]);
                $outletRow->stok_awal = round($qtyPakai, 3);
                $outletRow->saldo_awal_nominal = round($totalPriceVal, 2);
                $outletRow->tanggal_saldo_awal = $effectiveDate;
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
                    $ingredient->saldo_awal_nominal = round($totalPriceVal, 2);
                    $ingredient->tanggal_saldo_awal = $effectiveDate;
                    if ($minStock !== null) {
                        $ingredient->stok_min = $minStock;
                    }
                    if ($hargaBeli > 0) {
                        $ingredient->harga = $hargaBeli;
                        $ingredient->last_purchase_price = $hargaBeli;
                    }
                    $ingredient->save();
                }

                // Delete any legacy StockMovement of type 'INITIAL' for this ingredient & outlet to avoid duplicate/ghost rows
                StockMovement::where('ingredient_id', $ingredient->id)
                    ->where('outlet_id', $targetOutlet->id)
                    ->where('type', 'INITIAL')
                    ->delete();

                // Recompute Moving Average and HPP from history starting from the new initial stock
                $visited = [];
                $ingredient->recomputeMovingAverageFromHistory((int)$targetOutlet->id, $visited);

                $importedCount++;
            }
        });
        } catch (\Throwable $e) {
            try {
                \Illuminate\Support\Facades\Log::error("bulkImportInitial error: " . $e->getMessage());
            } catch (\Throwable $logEx) {}

            return response()->json([
                'message' => 'Gagal meng-import saldo awal stok: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message'        => "Berhasil meng-import {$importedCount} data stock awal fisik per gudang / cabang.",
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

    /**
     * Fitur Edit Mutasi Stok di Semua Baris & Akumulasi Ulang Moving Average Secara Otomatis
     */
    public function update(Request $request, StockMovement $movement)
    {
        $user = $request->user();
        if (!$user || (!$user->isOwnerBisnis() && !$user->isPlatformAdmin())) {
            return response()->json([
                'message' => 'Hanya Owner Bisnis atau Admin Platform yang berwenang mengubah data mutasi stok.'
            ], 403);
        }

        $validated = $request->validate([
            'date'           => 'required|date',
            'qty'            => 'required|numeric|min:0.0001',
            'unit_type'      => 'nullable|in:BELI,PAKAI',
            'unit_price'     => 'nullable|numeric|min:0',
            'total_price'    => 'nullable|numeric|min:0',
            'type'           => 'nullable|in:PURCHASE,WASTE,ADJUSTMENT_IN,ADJUSTMENT_OUT,TRANSFER_IN,TRANSFER_OUT,PREP_USAGE,PREP_OUTPUT',
            'waste_reason'   => 'nullable|string|max:50',
            'note'           => 'nullable|string|max:255',
            'payment_type'   => 'nullable|string|max:50',
            'supplier_name'  => 'nullable|string|max:150',
            'purchase_no'    => 'nullable|string|max:100',
            'due_date'       => 'nullable|date',
            'initial_paid'   => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|max:50',
        ]);

        $ingredient = Ingredient::findOrFail($movement->ingredient_id);
        $konversi = max((float)$ingredient->konversi, 1);

        $isUnitBeli = ($request->unit_type === 'BELI');
        $qtyPakai = $isUnitBeli ? (float)$validated['qty'] * $konversi : (float)$validated['qty'];

        // Determine price
        $pricePerBeli = null;
        if ($request->filled('unit_price') && (float)$request->unit_price > 0) {
            $inputPrice = (float)$request->unit_price;
            $pricePerBeli = $isUnitBeli ? $inputPrice : ($inputPrice * $konversi);
        } elseif ($request->filled('total_price') && (float)$request->total_price > 0 && $qtyPakai > 0) {
            $pricePerBeli = ((float)$request->total_price) / ($qtyPakai / $konversi);
        } else {
            $pricePerBeli = (float)($movement->unit_price ?: $ingredient->hargaForOutlet($movement->outlet_id));
        }

        $totalPrice = $request->filled('total_price') && (float)$request->total_price > 0
            ? (float)$request->total_price
            : round(($qtyPakai / $konversi) * $pricePerBeli, 2);

        $type = $validated['type'] ?? $movement->type;
        $rawPaymentType = strtoupper(trim($validated['payment_type'] ?? $movement->payment_type ?? 'CASH'));
        $paymentType = in_array($rawPaymentType, ['HUTANG', 'TEMPO']) ? 'HUTANG' : ($rawPaymentType === 'TRANSFER' ? 'TRANSFER' : 'CASH');

        $affectedOutletIds = [(int)$movement->outlet_id];

        DB::transaction(function () use ($movement, $validated, $qtyPakai, $pricePerBeli, $totalPrice, $type, $paymentType, $user, $ingredient, &$affectedOutletIds) {
            $movement->date         = $validated['date'];
            $movement->qty          = $qtyPakai;
            $movement->unit_price   = $pricePerBeli;
            $movement->total_price  = $totalPrice;
            $movement->type         = $type;
            $movement->payment_type = $paymentType;
            $movement->note         = $validated['note'] ?? $movement->note;
            $movement->waste_reason = $validated['waste_reason'] ?? $movement->waste_reason;
            $movement->supplier_name= $validated['supplier_name'] ?? $movement->supplier_name;
            $movement->purchase_no  = $validated['purchase_no'] ?? $movement->purchase_no;
            $movement->updated_by   = $user->id;
            $movement->saveQuietly();

            // 1. Jika terhubung dengan Hutang (Payable)
            if ($movement->payable_id) {
                $payable = Payable::find($movement->payable_id);
                if ($payable) {
                    $initialPaid = min((float)($validated['initial_paid'] ?? $payable->paid_amount), (float)$totalPrice);
                    $remaining = max(0, (float)$totalPrice - $initialPaid);
                    $payable->update([
                        'total_amount'     => $totalPrice,
                        'paid_amount'      => $initialPaid,
                        'remaining_amount' => $remaining,
                        'status'           => ($remaining <= 0) ? 'PAID' : ($initialPaid > 0 ? 'PARTIAL' : 'UNPAID'),
                        'due_date'         => $validated['due_date'] ?? $payable->due_date,
                        'supplier_name'    => $validated['supplier_name'] ?? $payable->supplier_name,
                        'purchase_no'      => $validated['purchase_no'] ?? $payable->purchase_no,
                        'notes'            => "Pembelian Stok Bahan: {$ingredient->name}" . (!empty($validated['note']) ? " ({$validated['note']})" : ''),
                    ]);
                }
            }

            // 2. Jika mutasi merupakan bagian dari Transfer Antar Cabang
            if ($movement->transfer_id) {
                // Update Transfer Item
                $transferItem = \App\Models\TransferItem::where('transfer_id', $movement->transfer_id)
                    ->where('ingredient_id', $movement->ingredient_id)
                    ->first();
                if ($transferItem) {
                    $transferItem->update([
                        'qty'         => $qtyPakai,
                        'input_qty'   => $validated['qty'],
                        'unit_price'  => $pricePerBeli,
                        'total_price' => $totalPrice,
                        'notes'       => $validated['note'] ?? $transferItem->notes,
                    ]);
                }

                // Update Paired Movement di cabang pasangan (lawan dari transfer ini)
                $pairedMovements = StockMovement::where('transfer_id', $movement->transfer_id)
                    ->where('ingredient_id', $movement->ingredient_id)
                    ->where('id', '!=', $movement->id)
                    ->get();

                foreach ($pairedMovements as $pm) {
                    $pm->date        = $validated['date'];
                    $pm->qty         = $qtyPakai;
                    $pm->unit_price  = $pricePerBeli;
                    $pm->total_price = $totalPrice;
                    $pm->note        = $validated['note'] ?? $pm->note;
                    $pm->updated_by  = $user->id;
                    $pm->saveQuietly();

                    if (!in_array((int)$pm->outlet_id, $affectedOutletIds)) {
                        $affectedOutletIds[] = (int)$pm->outlet_id;
                    }
                }
            }

            // 3. Akumulasi ulang Moving Average & Saldo untuk seluruh outlet yang terdampak (dengan auto-cascade ke cabang penerima)
            $visitedOutlets = [];
            foreach ($affectedOutletIds as $oid) {
                $ingredient->recomputeMovingAverageFromHistory($oid, $visitedOutlets);
            }
        });

        $movement->refresh()->load(['ingredient', 'user', 'creator', 'updater', 'outlet']);

        return response()->json([
            'message'  => "Data mutasi berhasil diperbarui dan seluruh akumulasi saldo serta moving average di outlet terkait telah dihitung ulang.",
            'movement' => $movement,
        ]);
    }

    /**
     * Hitung ulang (recompute) seluruh Moving Average dan akumulasi transfer untuk bahan (atau semua bahan) di seluruh cabang.
     */
    public function recalculateAll(Request $request)
    {
        $user = $request->user();
        if (!$user || (!$user->isOwnerBisnis() && !$user->isPlatformAdmin())) {
            return response()->json([
                'message' => 'Hanya Owner Bisnis atau Admin Platform yang berwenang melakukan rekalkulasi akumulasi.'
            ], 403);
        }

        $outlets = Outlet::all();
        $mainOutlet = $outlets->firstWhere('is_main', true) ?: $outlets->first();

        if ($request->filled('ingredient_id')) {
            $ingredients = Ingredient::where('id', $request->ingredient_id)->get();
        } else {
            $ingredients = Ingredient::all();
        }

        DB::transaction(function () use ($ingredients, $outlets, $mainOutlet) {
            foreach ($ingredients as $ing) {
                $visited = [];
                if ($mainOutlet) {
                    $ing->recomputeMovingAverageFromHistory((int)$mainOutlet->id, $visited);
                }
                foreach ($outlets as $out) {
                    if (!in_array((int)$out->id, $visited)) {
                        $ing->recomputeMovingAverageFromHistory((int)$out->id, $visited);
                    }
                }
            }
        });

        return response()->json([
            'message'           => 'Seluruh riwayat transaksi mutasi, HPP Moving Average, dan transfer antar-cabang telah berhasil dihitung ulang dan disinkronkan secara konsisten.',
            'total_ingredients' => count($ingredients),
        ]);
    }

    /**
     * Fitur Hapus / Rollback Mutasi Stok (Khusus Data Terakhir & Otoritas Owner Bisnis)
     * Mengembalikan saldo stok dan menghitung ulang Moving Average bahan secara otomatis.
     */
    public function destroy(Request $request, StockMovement $movement)
    {
        $user = $request->user();
        if (!$user || (!$user->isOwnerBisnis() && !$user->isPlatformAdmin())) {
            return response()->json([
                'message' => 'Hanya Owner Bisnis atau Admin Platform yang berwenang menghapus data mutasi stok.'
            ], 403);
        }

        // Cek apakah ada mutasi stok yang lebih baru untuk bahan & outlet ini
        $hasNewerMovement = StockMovement::where('ingredient_id', $movement->ingredient_id)
            ->where('outlet_id', $movement->outlet_id)
            ->where(function ($q) use ($movement) {
                $q->where('date', '>', $movement->date)
                  ->orWhere(function ($sub) use ($movement) {
                      $sub->where('date', $movement->date)->where('id', '>', $movement->id);
                  });
            })
            ->exists();

        if ($hasNewerMovement) {
            return response()->json([
                'message' => 'Hanya transaksi mutasi paling terakhir pada bahan & cabang ini yang dapat dihapus agar saldo dan perhitungan HPP Moving Average tetap akurat.'
            ], 422);
        }

        $ingredientId = $movement->ingredient_id;
        $outletId     = $movement->outlet_id;
        $refNo        = $movement->ref ?: "MVT-{$movement->id}";

        DB::transaction(function () use ($movement, $ingredientId, $outletId) {
            // Jika mutasi terkait hutang pembelian
            if ($movement->payable_id) {
                PayablePayment::where('payable_id', $movement->payable_id)->delete();
                Payable::where('id', $movement->payable_id)->delete();
            }

            // Hapus record mutasi
            $movement->delete();

            // Hitung ulang Moving Average dan Saldo Stok
            $ingredient = Ingredient::find($ingredientId);
            if ($ingredient && $outletId) {
                $visitedOutlets = [];
                $ingredient->recomputeMovingAverageFromHistory((int)$outletId, $visitedOutlets);
            }
        });

        return response()->json([
            'message' => "Transaksi mutasi {$refNo} berhasil dihapus. Saldo stok dan Moving Average (HPP) telah dikalkulasikan ulang secara real-time.",
        ]);
    }

    /**
     * Reset Kartu Stok (Stock Movements & Inventory Balances)
     * Khusus Owner Bisnis / Admin Platform.
     */
    public function resetStockCard(Request $request)
    {
        $user = $request->user();
        if (!$user || (!$user->isOwnerBisnis() && !$user->isPlatformAdmin())) {
            return response()->json([
                'message' => 'Hanya Owner Bisnis atau Admin Platform yang berwenang mereset kartu stok.'
            ], 403);
        }

        $request->validate([
            'scope'         => 'required|in:SELECTED_INGREDIENT,CURRENT_OUTLET,ALL_OUTLETS',
            'outlet_id'     => 'nullable|exists:outlets,id',
            'ingredient_id' => 'nullable|exists:ingredients,id',
            'keep_initial'  => 'boolean',
        ]);

        $scope = $request->input('scope', 'CURRENT_OUTLET');
        $outletId = $request->input('outlet_id');
        $ingredientId = $request->input('ingredient_id');
        $keepInitial = $request->boolean('keep_initial', false);

        if ($scope === 'SELECTED_INGREDIENT' && !$ingredientId) {
            return response()->json(['message' => 'Pilih bahan yang ingin di-reset kartu stoknya.'], 422);
        }
        if ($scope === 'CURRENT_OUTLET' && !$outletId) {
            return response()->json(['message' => 'Pilih cabang yang ingin di-reset kartu stoknya.'], 422);
        }

        $businessId = $user->business_id;

        $deletedMovementsCount = 0;
        $updatedIngredientsCount = 0;

        DB::transaction(function () use ($scope, $outletId, $ingredientId, $keepInitial, $businessId, &$deletedMovementsCount, &$updatedIngredientsCount) {
            // Build movement query
            $movQuery = StockMovement::query();

            // Scope filter
            if ($scope === 'SELECTED_INGREDIENT') {
                $movQuery->where('ingredient_id', $ingredientId);
                if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
                    $movQuery->where('outlet_id', $outletId);
                }
            } elseif ($scope === 'CURRENT_OUTLET') {
                $movQuery->where('outlet_id', $outletId);
            } elseif ($scope === 'ALL_OUTLETS') {
                if ($businessId) {
                    $businessOutletIds = Outlet::where('business_id', $businessId)->pluck('id');
                    $movQuery->whereIn('outlet_id', $businessOutletIds);
                }
            }

            // Keep initial stock mutations if requested
            if ($keepInitial) {
                $movQuery->where('type', '!=', 'INITIAL');
            }

            // Get IDs to delete and clean payables
            $movementsToDelete = $movQuery->get();
            $deletedMovementsCount = $movementsToDelete->count();

            // Collect payable IDs attached to these movements to clean them up
            $payableIds = $movementsToDelete->pluck('payable_id')->filter()->unique()->all();
            if (!empty($payableIds)) {
                PayablePayment::whereIn('payable_id', $payableIds)->delete();
                Payable::whereIn('id', $payableIds)->delete();
            }

            // Delete movements
            if ($deletedMovementsCount > 0) {
                $movementIds = $movementsToDelete->pluck('id')->all();
                StockMovement::whereIn('id', $movementIds)->delete();
            }

            // Update / Reset OutletIngredient and Ingredient balances
            $outletIngQuery = OutletIngredient::query();
            if ($scope === 'SELECTED_INGREDIENT') {
                $outletIngQuery->where('ingredient_id', $ingredientId);
                if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
                    $outletIngQuery->where('outlet_id', $outletId);
                }
            } elseif ($scope === 'CURRENT_OUTLET') {
                $outletIngQuery->where('outlet_id', $outletId);
            } elseif ($scope === 'ALL_OUTLETS') {
                if ($businessId) {
                    $businessOutletIds = Outlet::where('business_id', $businessId)->pluck('id');
                    $outletIngQuery->whereIn('outlet_id', $businessOutletIds);
                }
            }

            $affectedOutletIngredients = $outletIngQuery->get();
            $updatedIngredientsCount = $affectedOutletIngredients->count();

            foreach ($affectedOutletIngredients as $oi) {
                if (!$keepInitial) {
                    $oi->stok_awal = 0;
                    $oi->saldo_awal_nominal = 0;
                    $oi->tanggal_saldo_awal = null;
                    $oi->save();
                }
            }

            if (!$keepInitial) {
                if ($scope === 'SELECTED_INGREDIENT') {
                    Ingredient::where('id', $ingredientId)->update(['stok_awal' => 0, 'saldo_awal_nominal' => 0, 'tanggal_saldo_awal' => null]);
                } elseif ($scope === 'ALL_OUTLETS') {
                    if ($businessId) {
                        Ingredient::where('business_id', $businessId)->update(['stok_awal' => 0, 'saldo_awal_nominal' => 0, 'tanggal_saldo_awal' => null]);
                    } else {
                        Ingredient::query()->update(['stok_awal' => 0, 'saldo_awal_nominal' => 0, 'tanggal_saldo_awal' => null]);
                    }
                }
            }

            // Recompute / sync for affected ingredients
            if ($scope === 'SELECTED_INGREDIENT') {
                $ing = Ingredient::find($ingredientId);
                if ($ing) {
                    $visited = [];
                    if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
                        $ing->recomputeMovingAverageFromHistory((int)$outletId, $visited);
                    } else {
                        $outlets = Outlet::all();
                        foreach ($outlets as $o) {
                            $ing->recomputeMovingAverageFromHistory((int)$o->id, $visited);
                        }
                    }
                }
            } else {
                $allIngs = Ingredient::all();
                $outlets = Outlet::all();
                $mainOutlet = $outlets->firstWhere('is_main', true) ?: $outlets->first();
                foreach ($allIngs as $ing) {
                    $visited = [];
                    if ($mainOutlet) {
                        $ing->recomputeMovingAverageFromHistory((int)$mainOutlet->id, $visited);
                    }
                    foreach ($outlets as $out) {
                        if (!in_array((int)$out->id, $visited)) {
                            $ing->recomputeMovingAverageFromHistory((int)$out->id, $visited);
                        }
                    }
                }
            }
        });

        $scopeText = match ($scope) {
            'SELECTED_INGREDIENT' => 'Bahan Terpilih',
            'CURRENT_OUTLET'      => 'Cabang Terpilih',
            'ALL_OUTLETS'         => 'Seluruh Cabang Bisnis',
            default               => 'Kartu Stok',
        };

        $modeText = $keepInitial ? 'dengan mempertahankan Saldo Awal Fisik' : 'secara menyeluruh (termasuk Saldo Awal)';

        return response()->json([
            'message'                   => "Kartu stok untuk {$scopeText} berhasil di-reset {$modeText}. ({$deletedMovementsCount} baris mutasi dihapus).",
            'deleted_movements_count'   => $deletedMovementsCount,
            'updated_ingredients_count' => $updatedIngredientsCount,
        ]);
    }
}

