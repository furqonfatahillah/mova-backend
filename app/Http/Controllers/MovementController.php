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
use App\Services\AccountingService;
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
        $businessId = $user ? $user->business_id : null;

        try {
            TransferController::syncMissingWasteMovements($businessId);
        } catch (\Throwable $e) {}

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
        if (in_array($request->type, ['ADJUSTMENT_IN', 'ADJUSTMENT_OUT'])) {
            return response()->json([
                'message' => 'Penyesuaian stok (adjustment) ditiadakan di Catat Mutasi. Silakan lakukan penyesuaian melalui menu Stock Opname.'
            ], 422);
        }

        $data = $request->validate([
            'date'              => 'required|date',
            'ingredient_id'     => 'required|exists:ingredients,id',
            'type'              => 'required|in:PURCHASE,WASTE,TRANSFER_IN,TRANSFER_OUT,PREP_USAGE,PREP_OUTPUT',
            'waste_reason'      => 'nullable|string|max:50',
            'qty'               => 'required|numeric|min:0.001',
            'unit_price'        => 'nullable|numeric|min:0',
            'total_price'       => 'nullable|numeric|min:0',
            'unit_type'         => 'nullable|in:BELI,PAKAI',
            'note'              => 'nullable|string|max:255',
            'outlet_id'         => 'nullable|exists:outlets,id',
            'negative_handling' => 'nullable|string|in:RESET_TO_ZERO,OFFSET_NEGATIVE,BARANG_BARU,LUPA_TERINPUT',
            // Payment / Hutang fields
            'payment_type'      => 'nullable|string|max:50',
            'supplier_name'     => 'nullable|string|max:150',
            'supplier_phone'    => 'nullable|string|max:50',
            'supplier_id'       => 'nullable|integer',
            'purchase_no'       => 'nullable|string|max:100',
            'due_date'          => 'nullable|date',
            'initial_paid'      => 'nullable|numeric|min:0',
            'payment_method'    => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $businessId = $user ? $user->business_id : null;
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $outletId = (int)($data['outlet_id'] ?? $user?->outlet_id ?? Outlet::when($businessId, fn($q) => $q->where('business_id', $businessId))->value('id'));
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
            // Outlet Cabang: Tidak bisa Hutang Supplier (Cash, Bank, QRIS, atau Kas Besar)
            $isHutang = false;
            $paymentType = in_array($rawPaymentType, ['BANK', 'TRANSFER', 'QRIS', 'KAS_BESAR', 'PETTY_CASH']) ? $rawPaymentType : 'CASH';
        } else {
            // Holding: Hutang, Kas Besar, Kasir, Bank, QRIS
            $isHutang = in_array($rawPaymentType, ['HUTANG', 'TEMPO', 'CREDIT']);
            $paymentType = $isHutang ? 'HUTANG' : ($rawPaymentType ?: 'CASH');
        }
        $payableId = null;

        if ($data['type'] === 'PURCHASE') {
            $currentStock = $ingredient->stockForOutlet($outletId);
            $negHandling = strtoupper(trim($request->negative_handling ?? 'RESET_TO_ZERO'));

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

            // Kategori: Lupa Menginputkan vs Barang Baru Datang
            $movementDate = $data['date'];
            $movementCreatedAt = null;

            if ($currentStock < -0.0001 && in_array($negHandling, ['LUPA_TERINPUT', 'OFFSET_NEGATIVE'])) {
                // 1. OPSI: Lupa Menginputkan Pembelian
                // Cari transaksi pertama yang berstatus minus untuk diletakkan di atas transaksi tersebut
                $earliestNeg = $this->findEarliestNegativeMovement($ingredient->id, $outletId);
                if ($earliestNeg) {
                    $movementDate = $earliestNeg->date;
                    $negCreatedAt = $earliestNeg->created_at ? Carbon::parse($earliestNeg->created_at) : Carbon::parse($earliestNeg->date . ' 00:00:00');
                    $movementCreatedAt = $negCreatedAt->copy()->subSecond();

                    // Selesaikan status Urgent Note yang pending untuk bahan ini
                    UrgentNote::where('ingredient_id', $ingredient->id)
                        ->where('outlet_id', $outletId)
                        ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                        ->update([
                            'status'           => 'RESOLVED',
                            'resolved_at'      => now(),
                            'resolved_by'      => $user?->id,
                            'resolution_notes' => 'Terselesaikan otomatis melalui pencatatan mutasi pembelian yang disisipkan sebelum transaksi minus.',
                        ]);
                }
            } elseif ($currentStock < -0.0001 && in_array($negHandling, ['RESET_TO_ZERO', 'BARANG_BARU'])) {
                // 2. OPSI: Barang Baru Datang
                // Reset stok minus ke 0 via penyesuaian normalisasi di kartu stok agar data baru dimulai bersih dari 0
                // Kunci avg sebelumnya agar nominal defisit ter-nol-kan secara presisi
                $minusQty = abs((float)$currentStock);

                $deficitCostPerPakai = (float)$ingredient->costPerPakaiForOutlet($outletId);
                $lastMovement = StockMovement::where('ingredient_id', $ingredient->id)
                    ->where('outlet_id', $outletId)
                    ->orderBy('date', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->orderBy('id', 'desc')
                    ->first();
                if ($lastMovement) {
                    if ((float)$lastMovement->cost_after > 0) {
                        $deficitCostPerPakai = (float)$lastMovement->cost_after;
                    } elseif ((float)$lastMovement->cost_before > 0) {
                        $deficitCostPerPakai = (float)$lastMovement->cost_before;
                    }
                }
                if ($deficitCostPerPakai <= 0) {
                    $deficitCostPerPakai = (float)$ingredient->hargaForOutlet($outletId) / $konversi;
                }

                $lockedPricePerPakai = $deficitCostPerPakai;
                $lockedPricePerBeli = round($lockedPricePerPakai * $konversi, 4);
                $adjTotalPrice = round(($minusQty / $konversi) * $lockedPricePerBeli, 2);

                $adjCreatedAt = ($movementCreatedAt ? Carbon::parse($movementCreatedAt) : now())->copy()->subSecond();

                $adjMovement = StockMovement::create([
                    'business_id'    => $user?->business_id,
                    'outlet_id'      => $outletId,
                    'ingredient_id'  => $ingredient->id,
                    'date'           => $data['date'],
                    'type'           => 'ADJUSTMENT_IN',
                    'qty'            => $minusQty,
                    'unit_price'     => $lockedPricePerBeli,
                    'total_price'    => $adjTotalPrice,
                    'cost_before'    => round($lockedPricePerPakai, 4),
                    'cost_after'     => round($lockedPricePerPakai, 4),
                    'note'           => "Reset Stok Minus (Barang Baru Masuk) – Normalisasi ke 0 sebelum Pembelian" . (!empty($data['purchase_no']) ? " #{$data['purchase_no']}" : ""),
                    'user_id'        => $user?->id,
                    'created_by'     => $user?->id,
                    'created_at'     => $adjCreatedAt,
                ]);
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

        $fallbackNote = null;
        if ($data['type'] === 'PURCHASE') {
            $supp = $data['supplier_name'] ?? null;
            $inv = $data['purchase_no'] ?? null;
            if ($supp && $inv) $fallbackNote = "Pembelian: {$supp} (#{$inv})";
            elseif ($supp) $fallbackNote = "Pembelian: {$supp}";
            elseif ($inv) $fallbackNote = "Pembelian (Faktur #{$inv})";
            else $fallbackNote = "Pembelian Bahan Masuk";
        } elseif ($data['type'] === 'ADJUSTMENT_IN') {
            $fallbackNote = "Penyesuaian Stok Masuk (+)";

            $lastAvgPerPakai = (float)$costBefore;
            if ($lastAvgPerPakai <= 0) {
                $lastAvgPerPakai = (float)$ingredient->hargaForOutlet($outletId) / $konversi;
            }
            $lastAvgPerBeli = round($lastAvgPerPakai * $konversi, 4);

            // ── Kunci avg saat defisit & split baris jika ada surplus ─────────────────
            // Pada penyesuaian stok, harga terkunci otomatis ke harga rata-rata terakhir (tidak diinput user).
            // Jika stok sedang defisit (negatif), defisit dinormalkan dengan avg lama.
            // ──────────────────────────────────────────────────────────────────────────
            $currentStockQty = $ingredient->stockForOutlet($outletId);
            if ($currentStockQty < -0.0001) {
                $deficitQty = abs((float)$currentStockQty);

                if ($qtyPakai > $deficitQty + 0.0001) {
                    // 1. Buat Baris Penyesuaian Normalisasi untuk me-0-kan defisit dengan avg terkunci
                    $createdAtBase = $movementCreatedAt ?: now();
                    StockMovement::create([
                        'business_id'   => $user?->business_id,
                        'date'          => $movementDate ?? $data['date'],
                        'ingredient_id' => $data['ingredient_id'],
                        'outlet_id'     => $outletId,
                        'type'          => 'ADJUSTMENT_IN',
                        'qty'           => $deficitQty,
                        'unit_price'    => $lastAvgPerBeli,
                        'total_price'   => round(($deficitQty / $konversi) * $lastAvgPerBeli, 2),
                        'cost_before'   => round($lastAvgPerPakai, 4),
                        'cost_after'    => round($lastAvgPerPakai, 4),
                        'note'          => ($data['note'] ? $data['note'] . " – " : "") . "Penyesuaian Normalisasi Stok Minus ke 0",
                        'user_id'       => $request->user()->id,
                        'created_by'    => $request->user()->id,
                        'created_at'    => $createdAtBase,
                    ]);

                    // 2. Baris Utama menjadi baris selanjutnya (surplus)
                    $surplusQty   = $qtyPakai - $deficitQty;
                    $qtyPakai     = $surplusQty;
                    $unitPrice    = $lastAvgPerBeli;
                    $totalPrice   = round(($surplusQty / $konversi) * $lastAvgPerBeli, 2);
                    $costBefore   = round($lastAvgPerPakai, 4);
                    $costAfter    = round($lastAvgPerPakai, 4);
                    $fallbackNote = "Penyesuaian Stok Masuk (+)";
                    if ($movementCreatedAt) {
                        $movementCreatedAt = Carbon::parse($movementCreatedAt)->addSecond();
                    }
                } else {
                    // Qty adjustment <= defisit: cukup 1 baris mengunci avg lama
                    $unitPrice  = $lastAvgPerBeli;
                    $totalPrice = round(($qtyPakai / $konversi) * $unitPrice, 2);
                    $costBefore = round($lastAvgPerPakai, 4);
                    $costAfter  = round($lastAvgPerPakai, 4);
                }
            } else {
                // Stok normal (>= 0): gunakan avg harga terakhir
                $unitPrice  = $lastAvgPerBeli;
                $totalPrice = round(($qtyPakai / $konversi) * $unitPrice, 2);
                $costBefore = round($lastAvgPerPakai, 4);
                $costAfter  = round($lastAvgPerPakai, 4);
            }
        } elseif ($data['type'] === 'ADJUSTMENT_OUT') {
            $fallbackNote = "Penyesuaian Stok Keluar (-)";

            $lastAvgPerPakai = (float)$costBefore;
            if ($lastAvgPerPakai <= 0) {
                $lastAvgPerPakai = (float)$ingredient->hargaForOutlet($outletId) / $konversi;
            }
            $lastAvgPerBeli = round($lastAvgPerPakai * $konversi, 4);

            $unitPrice  = $lastAvgPerBeli;
            $totalPrice = round(($qtyPakai / $konversi) * $unitPrice, 2);
            $costBefore = round($lastAvgPerPakai, 4);
            $costAfter  = round($lastAvgPerPakai, 4);
        } elseif ($data['type'] === 'WASTE') {
            $wReason = $data['waste_reason'] ?? 'SPOILED';
            $fallbackNote = "Pencatatan Waste / Rusak ({$wReason})";
        }

        $movement = StockMovement::create([
            'date'          => $movementDate ?? $data['date'],
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
            'note'          => (!empty($data['note']) ? $data['note'] : $fallbackNote),
            'user_id'       => $request->user()->id,
            'created_by'    => $request->user()->id,
            'created_at'    => $movementCreatedAt ?: now(),
        ]);

        if ($movementCreatedAt) {
            $movement->created_at = $movementCreatedAt;
            $movement->save();
        }

        if (in_array($movement->type, ['PURCHASE', 'RESTOCK', 'IN'])) {
            $unResNotes = isset($adjMovement)
                ? 'Terselesaikan otomatis melalui penyesuaian normalisasi reset stok minus ke 0.'
                : 'Terselesaikan otomatis melalui pencatatan mutasi pembelian bahan masuk.';

            $urgentNotesToResolve = UrgentNote::where('ingredient_id', $ingredient->id)
                ->where('outlet_id', $outletId)
                ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                ->get();

            UrgentNote::where('ingredient_id', $ingredient->id)
                ->where('outlet_id', $outletId)
                ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                ->update([
                    'status'                 => 'RESOLVED',
                    'resolved_at'            => now(),
                    'resolved_by'            => $user?->id,
                    'resolution_notes'       => $unResNotes,
                    'resolution_movement_id' => isset($adjMovement) ? $adjMovement->id : $movement->id,
                ]);

            foreach ($urgentNotesToResolve as $unItem) {
                try {
                    $unItem->refresh();
                    AccountingService::postUrgentNoteResolution($unItem);
                } catch (\Throwable $e) {}
            }

            if ((float)$movement->total_price > 0 || (float)$movement->qty > 0) {
                AccountingService::postStockMovementPurchase($movement);
            }
        } elseif (in_array($movement->type, ['ADJUSTMENT_IN', 'ADJUSTMENT_PLUS', 'ADJUSTMENT_OUT']) && ((float)$movement->total_price > 0 || (float)$movement->qty > 0)) {
            AccountingService::postStockMovementAdjustment($movement);
        }

        // Recalculate moving average and update subsequent rows chronologically
        $ingredient->recomputeMovingAverageFromHistory($outletId);

        if (isset($adjMovement)) {
            AccountingService::postStockMovementAdjustment($adjMovement->fresh());
        }

        $movement->refresh();
        $movement->load(['ingredient', 'user', 'creator', 'updater', 'outlet', 'payable']);
        return response()->json($movement, 201);
    }


    public function bulkStore(Request $request)
    {
        if (in_array($request->type, ['ADJUSTMENT_IN', 'ADJUSTMENT_OUT'])) {
            return response()->json([
                'message' => 'Penyesuaian stok (adjustment) ditiadakan di Catat Mutasi. Silakan lakukan penyesuaian melalui menu Stock Opname.'
            ], 422);
        }

        $validated = $request->validate([
            'date'                  => 'required|date',
            'outlet_id'             => 'nullable|exists:outlets,id',
            'type'                  => 'required|in:PURCHASE,WASTE,TRANSFER_IN,TRANSFER_OUT,PREP_USAGE,PREP_OUTPUT',
            'negative_handling'     => 'nullable|string|in:RESET_TO_ZERO,OFFSET_NEGATIVE,BARANG_BARU,LUPA_TERINPUT',
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
            'items.*.negative_handling' => 'nullable|string|in:RESET_TO_ZERO,OFFSET_NEGATIVE,BARANG_BARU,LUPA_TERINPUT',
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
            // Outlet Cabang: Tidak bisa Hutang Supplier (Cash, Bank, QRIS, atau Kas Besar)
            $isHutang = false;
            $paymentType = in_array($rawPaymentType, ['BANK', 'TRANSFER', 'QRIS', 'KAS_BESAR', 'PETTY_CASH']) ? $rawPaymentType : 'CASH';
        } else {
            // Holding: Hutang, Kas Besar, Kasir, Bank, QRIS
            $isHutang = in_array($rawPaymentType, ['HUTANG', 'TEMPO', 'CREDIT']);
            $paymentType = $isHutang ? 'HUTANG' : ($rawPaymentType ?: 'CASH');
        }

        $result = DB::transaction(function () use ($validated, $user, $outletId, $businessId, $isHutang, $paymentType) {
            $itemsData = [];
            $grandTotal = 0.0;
            $itemNames = [];
            $adjMovements = [];

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

                $itemMovementDate = $validated['date'];
                $itemMovementCreatedAt = null;

                if ($validated['type'] === 'PURCHASE') {
                    $cStock = $ingredient->stockForOutlet($outletId);
                    $negHandling = strtoupper(trim($item['negative_handling'] ?? ($validated['negative_handling'] ?? 'RESET_TO_ZERO')));

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

                    if (in_array($negHandling, ['LUPA_TERINPUT', 'OFFSET_NEGATIVE'])) {
                        // 1. Opsi Lupa Menginputkan: Cari mutasi minus pertama
                        $earliestNeg = $this->findEarliestNegativeMovement($ingredient->id, $outletId);
                        if ($earliestNeg) {
                            $itemMovementDate = $earliestNeg->date;
                            $negCreatedAt = $earliestNeg->created_at ? Carbon::parse($earliestNeg->created_at) : Carbon::parse($earliestNeg->date . ' 00:00:00');
                            $itemMovementCreatedAt = $negCreatedAt->copy()->subSecond();

                            // Selesaikan status Urgent Note yang pending untuk bahan ini
                            UrgentNote::where('ingredient_id', $ingredient->id)
                                ->where('outlet_id', $outletId)
                                ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                                ->update([
                                    'status'           => 'RESOLVED',
                                    'resolved_at'      => now(),
                                    'resolved_by'      => $user?->id,
                                    'resolution_notes' => 'Terselesaikan otomatis melalui pencatatan mutasi pembelian yang disisipkan sebelum transaksi minus.',
                                ]);
                        }
                    } elseif ($cStock < -0.0001 && in_array($negHandling, ['RESET_TO_ZERO', 'BARANG_BARU'])) {
                        // 2. Opsi Barang Baru Datang: Normalisasi ke 0 dengan mengunci avg sebelumnya
                        $minusQty = abs((float)$cStock);

                        $deficitCostPerPakai = (float)$ingredient->costPerPakaiForOutlet($outletId);
                        $lastMovement = StockMovement::where('ingredient_id', $ingredient->id)
                            ->where('outlet_id', $outletId)
                            ->orderBy('date', 'desc')
                            ->orderBy('created_at', 'desc')
                            ->orderBy('id', 'desc')
                            ->first();
                        if ($lastMovement) {
                            if ((float)$lastMovement->cost_after > 0) {
                                $deficitCostPerPakai = (float)$lastMovement->cost_after;
                            } elseif ((float)$lastMovement->cost_before > 0) {
                                $deficitCostPerPakai = (float)$lastMovement->cost_before;
                            }
                        }
                        if ($deficitCostPerPakai <= 0) {
                            $deficitCostPerPakai = (float)$ingredient->hargaForOutlet($outletId) / $konversi;
                        }

                        $lockedPricePerPakai = $deficitCostPerPakai;
                        $lockedPricePerBeli = round($lockedPricePerPakai * $konversi, 4);
                        $adjTotalPrice = round(($minusQty / $konversi) * $lockedPricePerBeli, 2);

                        $adjCreatedAt = ($itemMovementCreatedAt ? Carbon::parse($itemMovementCreatedAt) : now())->copy()->subSecond();

                        $adjMvt = StockMovement::create([
                            'business_id'    => $businessId,
                            'outlet_id'      => $outletId,
                            'ingredient_id'  => $ingredient->id,
                            'date'           => $validated['date'],
                            'type'           => 'ADJUSTMENT_IN',
                            'qty'            => $minusQty,
                            'unit_price'     => $lockedPricePerBeli,
                            'total_price'    => $adjTotalPrice,
                            'cost_before'    => round($lockedPricePerPakai, 4),
                            'cost_after'     => round($lockedPricePerPakai, 4),
                            'note'           => "Reset Stok Minus (Barang Baru Masuk) – Normalisasi ke 0 sebelum Pembelian" . (!empty($validated['purchase_no']) ? " #{$validated['purchase_no']}" : ""),
                            'user_id'        => $user?->id,
                            'created_by'     => $user?->id,
                            'created_at'     => $adjCreatedAt,
                        ]);
                        $adjMovements[] = $adjMvt;
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
                } elseif ($validated['type'] === 'ADJUSTMENT_IN') {
                    $lastAvgPerPakai = (float)$costBefore;
                    if ($lastAvgPerPakai <= 0) {
                        $lastAvgPerPakai = (float)$ingredient->hargaForOutlet($outletId) / $konversi;
                    }
                    $lastAvgPerBeli = round($lastAvgPerPakai * $konversi, 4);

                    $cStockAdj = $ingredient->stockForOutlet($outletId);

                    if ($cStockAdj < -0.0001) {
                        $deficitQty = abs((float)$cStockAdj);

                        if ($qtyPakai > $deficitQty + 0.0001) {
                            // Baris 1: Me-0-kan defisit dengan locked avg
                            $itemsData[] = [
                                'ingredient'   => $ingredient,
                                'date'         => $itemMovementDate,
                                'created_at'   => $itemMovementCreatedAt,
                                'qty_pakai'    => $deficitQty,
                                'unit_price'   => $lastAvgPerBeli,
                                'total_price'  => round(($deficitQty / $konversi) * $lastAvgPerBeli, 2),
                                'cost_before'  => round($lastAvgPerPakai, 4),
                                'cost_after'   => round($lastAvgPerPakai, 4),
                                'waste_reason' => null,
                                'note'         => ($item['note'] ?? 'Penyesuaian Stok') . " – Normalisasi Stok Minus ke 0",
                            ];

                            // Baris 2: Sisa surplus
                            $surplusQty = $qtyPakai - $deficitQty;
                            $qtyPakai   = $surplusQty;
                            $unitPrice  = $lastAvgPerBeli;
                            $totalPrice = round(($surplusQty / $konversi) * $lastAvgPerBeli, 2);
                            $costBefore = round($lastAvgPerPakai, 4);
                            $costAfter  = round($lastAvgPerPakai, 4);
                        } else {
                            $unitPrice  = $lastAvgPerBeli;
                            $totalPrice = round(($qtyPakai / $konversi) * $unitPrice, 2);
                            $costBefore = round($lastAvgPerPakai, 4);
                            $costAfter  = round($lastAvgPerPakai, 4);
                        }
                    } else {
                        $unitPrice  = $lastAvgPerBeli;
                        $totalPrice = round(($qtyPakai / $konversi) * $unitPrice, 2);
                        $costBefore = round($lastAvgPerPakai, 4);
                        $costAfter  = round($lastAvgPerPakai, 4);
                    }
                } elseif ($validated['type'] === 'ADJUSTMENT_OUT') {
                    $lastAvgPerPakai = (float)$costBefore;
                    if ($lastAvgPerPakai <= 0) {
                        $lastAvgPerPakai = (float)$ingredient->hargaForOutlet($outletId) / $konversi;
                    }
                    $lastAvgPerBeli = round($lastAvgPerPakai * $konversi, 4);

                    $unitPrice  = $lastAvgPerBeli;
                    $totalPrice = round(($qtyPakai / $konversi) * $unitPrice, 2);
                    $costBefore = round($lastAvgPerPakai, 4);
                    $costAfter  = round($lastAvgPerPakai, 4);
                }

                $itemNames[] = $ingredient->name . ' (' . (float)$item['qty'] . ' ' . ($isUnitBeli ? ($ingredient->unit_beli ?: 'unit') : ($ingredient->unit_pakai ?: 'unit')) . ')';

                $itemsData[] = [
                    'ingredient'   => $ingredient,
                    'date'         => $itemMovementDate,
                    'created_at'   => $itemMovementCreatedAt,
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
                $fallbackNote = null;
                if ($validated['type'] === 'PURCHASE') {
                    $supp = $validated['supplier_name'] ?? null;
                    $inv = $validated['purchase_no'] ?? null;
                    if ($supp && $inv) $fallbackNote = "Pembelian: {$supp} (#{$inv})";
                    elseif ($supp) $fallbackNote = "Pembelian: {$supp}";
                    elseif ($inv) $fallbackNote = "Pembelian (Faktur #{$inv})";
                    else $fallbackNote = "Pembelian Bahan Masuk";
                } elseif ($validated['type'] === 'ADJUSTMENT_IN') {
                    $fallbackNote = "Penyesuaian Stok Masuk (+)";
                } elseif ($validated['type'] === 'ADJUSTMENT_OUT') {
                    $fallbackNote = "Penyesuaian Stok Keluar (-)";
                } elseif ($validated['type'] === 'WASTE') {
                    $wReason = $it['waste_reason'] ?? 'SPOILED';
                    $fallbackNote = "Pencatatan Waste / Rusak ({$wReason})";
                }

                $itemNote = !empty($it['note']) 
                    ? $it['note'] 
                    : (!empty($validated['notes']) ? $validated['notes'] : $fallbackNote);

                $movement = StockMovement::create([
                    'business_id'   => $businessId,
                    'date'          => $it['date'] ?? $validated['date'],
                    'ingredient_id' => $it['ingredient']->id,
                    'outlet_id'     => $outletId,
                    'type'          => $validated['type'],
                    'payment_type'  => $paymentType,
                    'supplier_name' => $validated['supplier_name'] ?? null,
                    'purchase_no'   => $validated['purchase_no'] ?? null,
                    'payable_id'    => $payableId,
                    'waste_reason'  => $it['waste_reason'] ?? null,
                    'qty'           => $it['qty_pakai'],
                    'unit_price'    => $it['unit_price'],
                    'total_price'   => $it['total_price'],
                    'cost_before'   => $it['cost_before'],
                    'cost_after'    => $it['cost_after'],
                    'note'          => $itemNote,
                    'user_id'       => $user?->id,
                    'created_by'    => $user?->id,
                    'created_at'    => $it['created_at'] ?: now(),
                ]);

                if (!empty($it['created_at'])) {
                    $movement->created_at = $it['created_at'];
                    $movement->save();
                }

                $movements[] = $movement;
            }

            if ($payable && count($movements) > 0) {
                $payable->update(['stock_movement_id' => $movements[0]->id]);
            }

            // Auto-resolve pending urgent notes & auto-post double-entry journal for purchases
            if (in_array($validated['type'], ['PURCHASE', 'RESTOCK', 'IN'])) {
                foreach ($itemsData as $it) {
                    $ingId = $it['ingredient']->id;
                    $matchedAdj = !empty($adjMovements) ? collect($adjMovements)->firstWhere('ingredient_id', $ingId) : null;
                    $unResNotes = $matchedAdj
                        ? 'Terselesaikan otomatis melalui penyesuaian normalisasi reset stok minus ke 0.'
                        : 'Terselesaikan otomatis melalui pencatatan mutasi pembelian bahan masuk.';

                    $urgentNotesToResolve = UrgentNote::where('ingredient_id', $ingId)
                        ->where('outlet_id', $outletId)
                        ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                        ->get();

                    UrgentNote::where('ingredient_id', $ingId)
                        ->where('outlet_id', $outletId)
                        ->whereIn('status', ['PENDING', 'APPROVAL_PENDING'])
                        ->update([
                            'status'                 => 'RESOLVED',
                            'resolved_at'            => now(),
                            'resolved_by'            => $user?->id,
                            'resolution_notes'       => $unResNotes,
                            'resolution_movement_id' => $matchedAdj ? $matchedAdj->id : ($movements[0]?->id ?? null),
                        ]);

                    foreach ($urgentNotesToResolve as $unItem) {
                        try {
                            $unItem->refresh();
                            AccountingService::postUrgentNoteResolution($unItem);
                        } catch (\Throwable $e) {}
                    }
                }
            }

            foreach ($movements as $m) {
                if (in_array($m->type, ['PURCHASE', 'RESTOCK', 'IN']) && ((float)$m->total_price > 0 || (float)$m->qty > 0)) {
                    AccountingService::postStockMovementPurchase($m);
                } elseif (in_array($m->type, ['ADJUSTMENT_IN', 'ADJUSTMENT_PLUS', 'ADJUSTMENT_OUT']) && ((float)$m->total_price > 0 || (float)$m->qty > 0)) {
                    AccountingService::postStockMovementAdjustment($m);
                }
            }

            // Recalculate moving average and update subsequent rows chronologically for each affected ingredient
            $uniqueIngs = collect($itemsData)->pluck('ingredient')->unique('id');
            foreach ($uniqueIngs as $ing) {
                $ing->recomputeMovingAverageFromHistory($outletId);
            }

            if (!empty($adjMovements)) {
                foreach ($adjMovements as $adjM) {
                    AccountingService::postStockMovementAdjustment($adjM->fresh());
                }
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
     * Generate Stock Card Summary list for all ingredients in an outlet or consolidated business within a date range
     */
    public function stockCardSummary(Request $request)
    {
        $request->validate([
            'from'      => 'required|date',
            'to'        => 'required|date|after_or_equal:from',
            'outlet_id' => 'nullable',
        ]);

        $from     = $request->from;
        $to       = $request->to;
        $user     = $request->user();
        $businessId = $user ? $user->business_id : null;

        try {
            TransferController::syncMissingWasteMovements($businessId);
        } catch (\Throwable $e) {}

        $outletId = null;
        $outlet = null;
        $isConsolidated = false;

        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
            $outlet = \App\Models\Outlet::find($outletId);
        } else {
            $rawOutletId = $request->outlet_id;
            if ($rawOutletId === 'ALL' || $rawOutletId === 'all' || empty($rawOutletId)) {
                $isConsolidated = true;
                $outletId = null;
            } else {
                $outletId = (int)$rawOutletId;
                $outlet = \App\Models\Outlet::find($outletId);
                if (!$outlet) {
                    $isConsolidated = true;
                    $outletId = null;
                }
            }
        }

        if (!$businessId) {
            $businessId = $outlet?->business_id ?: (\App\Models\Business::first()?->id ?: 1);
        }

        if ($isConsolidated) {
            $activeOutlets = $businessId ? \App\Models\Outlet::where('business_id', $businessId)->get() : collect();

            if ($activeOutlets->count() > 1) {
                // Multi-branch consolidation: calculate per-outlet and aggregate to guarantee 100% exact match with consolidated journal
                $aggregatedItems = [];
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

                foreach ($activeOutlets as $ot) {
                    $otData = $this->calculateSingleOutletStockCardSummary($businessId, $ot->id, $ot, $from, $to);
                    $s = $otData['summary'] ?? [];

                    $totalHppRp += (float)($s['total_hpp_rp'] ?? 0);
                    $totalTransferInRp += (float)($s['total_transfer_in_rp'] ?? 0);
                    $totalTransferInQty += (float)($s['total_transfer_in_qty'] ?? 0);
                    $totalTransferInCount += (int)($s['total_transfer_in_count'] ?? 0);
                    $totalTransferOutRp += (float)($s['total_transfer_out_rp'] ?? 0);
                    $totalTransferOutQty += (float)($s['total_transfer_out_qty'] ?? 0);
                    $totalTransferOutCount += (int)($s['total_transfer_out_count'] ?? 0);
                    $totalPembelianRp += (float)($s['total_pembelian_rp'] ?? 0);
                    $totalWasteRp += (float)($s['total_waste_rp'] ?? 0);
                    $totalWasteQty += (float)($s['total_waste_qty'] ?? 0);
                    $totalWasteCount += (int)($s['total_waste_count'] ?? 0);

                    foreach ($otData['items'] ?? [] as $it) {
                        $id = $it['id'];
                        if (!isset($aggregatedItems[$id])) {
                            $aggregatedItems[$id] = [
                                'id'                     => $it['id'],
                                'code'                   => $it['code'],
                                'name'                   => $it['name'],
                                'category'               => $it['category'],
                                'type'                   => $it['type'] ?? 'RAW',
                                'unit_pakai'             => $it['unit_pakai'],
                                'unit_beli'              => $it['unit_beli'],
                                'konversi'               => $it['konversi'],
                                'harga_satuan'           => $it['harga_satuan'],
                                'harga_beli'             => $it['harga_beli'],
                                'stok_min'               => 0.0,
                                'stok_awal'              => 0.0,
                                'nilai_saldo_awal'       => 0.0,
                                'tanggal_saldo_awal'     => $it['tanggal_saldo_awal'],
                                'total_masuk'            => 0.0,
                                'total_keluar'           => 0.0,
                                'nominal_masuk'          => 0.0,
                                'nominal_keluar'         => 0.0,
                                'total_penjualan'        => 0.0,
                                'nominal_hpp'            => 0.0,
                                'total_waste'            => 0.0,
                                'nominal_waste'          => 0.0,
                                'total_pembelian'        => 0.0,
                                'total_transfer_out'     => 0.0,
                                'nominal_transfer_out'   => 0.0,
                                'total_transfer_in'      => 0.0,
                                'nominal_transfer_in'    => 0.0,
                                'stok_akhir'             => 0.0,
                                'nilai_stok'             => 0.0,
                                'saldo_aman_rp'          => 0.0,
                                'saldo_minus_rp'         => 0.0,
                                'has_urgent_note'        => false,
                                'pending_urgent_qty'     => 0.0,
                                'pending_urgent_nominal' => 0.0,
                                'resolved_urgent_qty'    => 0.0,
                            ];
                        }

                        $aggregatedItems[$id]['stok_min'] += (float)$it['stok_min'];
                        $aggregatedItems[$id]['stok_awal'] += (float)$it['stok_awal'];
                        $aggregatedItems[$id]['nilai_saldo_awal'] += (float)$it['nilai_saldo_awal'];
                        $aggregatedItems[$id]['total_masuk'] += (float)$it['total_masuk'];
                        $aggregatedItems[$id]['total_keluar'] += (float)$it['total_keluar'];
                        $aggregatedItems[$id]['nominal_masuk'] += (float)$it['nominal_masuk'];
                        $aggregatedItems[$id]['nominal_keluar'] += (float)$it['nominal_keluar'];
                        $aggregatedItems[$id]['total_penjualan'] += (float)$it['total_penjualan'];
                        $aggregatedItems[$id]['nominal_hpp'] += (float)($it['nominal_hpp'] ?? 0);
                        $aggregatedItems[$id]['total_waste'] += (float)$it['total_waste'];
                        $aggregatedItems[$id]['nominal_waste'] += (float)($it['nominal_waste'] ?? 0);
                        $aggregatedItems[$id]['total_pembelian'] += (float)$it['total_pembelian'];
                        $aggregatedItems[$id]['total_transfer_out'] += (float)($it['total_transfer_out'] ?? 0);
                        $aggregatedItems[$id]['nominal_transfer_out'] += (float)($it['nominal_transfer_out'] ?? 0);
                        $aggregatedItems[$id]['total_transfer_in'] += (float)($it['total_transfer_in'] ?? 0);
                        $aggregatedItems[$id]['nominal_transfer_in'] += (float)($it['nominal_transfer_in'] ?? 0);
                        $aggregatedItems[$id]['stok_akhir'] += (float)$it['stok_akhir'];
                        $aggregatedItems[$id]['nilai_stok'] += (float)$it['nilai_stok'];
                        $aggregatedItems[$id]['saldo_aman_rp'] += (float)$it['saldo_aman_rp'];
                        $aggregatedItems[$id]['saldo_minus_rp'] += (float)$it['saldo_minus_rp'];
                        $aggregatedItems[$id]['pending_urgent_qty'] += (float)$it['pending_urgent_qty'];
                        $aggregatedItems[$id]['pending_urgent_nominal'] += (float)$it['pending_urgent_nominal'];
                        $aggregatedItems[$id]['resolved_urgent_qty'] += (float)$it['resolved_urgent_qty'];
                        if (!empty($it['has_urgent_note'])) {
                            $aggregatedItems[$id]['has_urgent_note'] = true;
                        }
                    }
                }

                $totalSafeValuation = 0.0;
                $totalDeficitValuation = 0.0;
                $totalSafeStock = 0;
                $totalNegativeStock = 0;
                $totalEmptyStock = 0;
                $totalLowStock = 0;

                foreach ($aggregatedItems as &$item) {
                    $item['stok_awal'] = round($item['stok_awal'], 3);
                    $item['nilai_saldo_awal'] = round($item['nilai_saldo_awal'], 2);
                    $item['total_masuk'] = round($item['total_masuk'], 3);
                    $item['total_keluar'] = round($item['total_keluar'], 3);
                    $item['nominal_masuk'] = round($item['nominal_masuk'], 2);
                    $item['nominal_keluar'] = round($item['nominal_keluar'], 2);
                    $item['total_penjualan'] = round($item['total_penjualan'], 3);
                    $item['nominal_hpp'] = round($item['nominal_hpp'] ?? 0, 2);
                    $item['total_waste'] = round($item['total_waste'], 3);
                    $item['nominal_waste'] = round($item['nominal_waste'] ?? 0, 2);
                    $item['total_pembelian'] = round($item['total_pembelian'], 3);
                    $item['total_transfer_out'] = round($item['total_transfer_out'] ?? 0, 3);
                    $item['nominal_transfer_out'] = round($item['nominal_transfer_out'] ?? 0, 2);
                    $item['total_transfer_in'] = round($item['total_transfer_in'] ?? 0, 3);
                    $item['nominal_transfer_in'] = round($item['nominal_transfer_in'] ?? 0, 2);
                    $item['stok_akhir'] = round($item['stok_akhir'], 3);
                    $item['nilai_stok'] = round($item['nilai_stok'], 2);
                    $item['saldo_aman_rp'] = round($item['saldo_aman_rp'], 2);
                    $item['saldo_minus_rp'] = round($item['saldo_minus_rp'], 2);

                    $isNegative = $item['stok_akhir'] < -0.0001;
                    $isEmpty    = abs($item['stok_akhir']) <= 0.0001;
                    $isLow      = $item['stok_akhir'] > 0 && $item['stok_akhir'] <= $item['stok_min'];
                    $isSafe     = $item['stok_akhir'] > 0.0001;

                    $item['is_negative'] = $isNegative;
                    $item['is_empty']    = $isEmpty;
                    $item['is_low']      = $isLow;
                    $item['is_safe']     = $isSafe;

                    if ($isNegative) {
                        $totalNegativeStock++;
                    } elseif ($isSafe) {
                        $totalSafeStock++;
                    } else {
                        $totalEmptyStock++;
                    }

                    if ($isLow) {
                        $totalLowStock++;
                    }

                    $totalSafeValuation += $item['saldo_aman_rp'];
                    $totalDeficitValuation += $item['saldo_minus_rp'];

                    if ($item['stok_akhir'] > 0.0001 && $item['nilai_stok'] > 0) {
                        $item['harga_satuan'] = round($item['nilai_stok'] / $item['stok_akhir'], 2);
                        $item['harga_beli'] = round($item['harga_satuan'] * $item['konversi'], 2);
                    } elseif ($item['stok_awal'] > 0.0001 && $item['nilai_saldo_awal'] > 0) {
                        $item['harga_satuan'] = round($item['nilai_saldo_awal'] / $item['stok_awal'], 2);
                        $item['harga_beli'] = round($item['harga_satuan'] * $item['konversi'], 2);
                    } else {
                        $item['harga_satuan'] = 0.0;
                        $item['harga_beli'] = 0.0;
                    }
                }
                unset($item);

                $items = array_values($aggregatedItems);

                $summary = [
                    'total_items'              => count($items),
                    'total_safe_items'         => $totalSafeStock,
                    'total_negative_items'     => $totalNegativeStock,
                    'total_empty_items'        => $totalEmptyStock,
                    'total_low_stock'          => $totalLowStock,
                    'total_safe_valuation'     => round($totalSafeValuation, 2),
                    'total_deficit_valuation'  => round($totalDeficitValuation, 2),
                    'net_total_valuation'      => round($totalSafeValuation + $totalDeficitValuation, 2),
                    'total_nilai'              => round($totalSafeValuation, 2),
                    'total_hpp_rp'             => round($totalHppRp, 2),
                    'total_transfer_in_rp'     => round($totalTransferInRp, 2),
                    'total_transfer_in_qty'    => round($totalTransferInQty, 2),
                    'total_transfer_in_count'  => $totalTransferInCount,
                    'total_transfer_out_rp'    => round($totalTransferOutRp, 2),
                    'total_transfer_out_qty'   => round($totalTransferOutQty, 2),
                    'total_transfer_out_count' => $totalTransferOutCount,
                    'total_pembelian_rp'       => round($totalPembelianRp, 2),
                    'total_waste_rp'           => round($totalWasteRp, 2),
                    'total_waste_qty'          => round($totalWasteQty, 2),
                    'total_waste_count'        => $totalWasteCount,
                    'total_nilai_saldo_awal_rp'=> round(array_sum(array_column($items, 'nilai_saldo_awal')), 2),
                    'total_nominal_masuk_rp'   => round(array_sum(array_column($items, 'nominal_masuk')), 2),
                    'total_nominal_keluar_rp'  => round(array_sum(array_column($items, 'nominal_keluar')), 2),
                ];
            } else {
                $targetOt = $activeOutlets->first();
                $singleData = $this->calculateSingleOutletStockCardSummary($businessId, $targetOt?->id, $targetOt, $from, $to);
                $items = $singleData['items'];
                $summary = $singleData['summary'];
            }
        } else {
            $singleData = $this->calculateSingleOutletStockCardSummary($businessId, $outletId, $outlet, $from, $to);
            $items = $singleData['items'];
            $summary = $singleData['summary'];
        }

        return response()->json([
            'outlet' => $outlet ? [
                'id'      => $outlet->id,
                'name'    => $outlet->name,
                'is_main' => (bool)$outlet->is_main,
                'is_all'  => false,
            ] : [
                'id'      => 'ALL',
                'name'    => 'Semua Cabang (Konsolidasi Usaha)',
                'is_main' => false,
                'is_all'  => true,
            ],
            'period' => [
                'from' => $from,
                'to'   => $to,
            ],
            'summary' => $summary,
            'items'   => $items,
        ]);
    }

    /**
     * Helper to compute Stock Card Summary for a single outlet.
     */
    private function calculateSingleOutletStockCardSummary(?int $businessId, ?int $outletId, $outlet, string $from, string $to): array
    {
        // Fetch all ingredients of the current business
        $ingQuery = Ingredient::query();
        if ($businessId) {
            $ingQuery->where('business_id', $businessId);
        }
        if ($outletId) {
            $ingQuery->with(['outletIngredients' => function($q) use ($outletId) {
                $q->where('outlet_id', $outletId);
            }]);
        } else {
            $ingQuery->with(['outletIngredients']);
        }
        $ingredients = $ingQuery->get();

        $ingIds = $ingredients->pluck('id')->all();

        // 1. Fetch prior movements strictly before $from (chronological order)
        $priorMovementsQuery = StockMovement::whereIn('ingredient_id', $ingIds)
            ->where('type', '!=', 'INITIAL')
            ->where('date', '<', $from);
        if ($outletId) {
            $priorMovementsQuery->where('outlet_id', $outletId);
        } elseif ($businessId) {
            $priorMovementsQuery->where('business_id', $businessId);
        }
        $allPriorMovements = $priorMovementsQuery
            ->orderBy('date', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->groupBy('ingredient_id');

        // 2. Fetch period movements between $from and $to (chronological order)
        $periodMovementsQuery = StockMovement::whereIn('ingredient_id', $ingIds)
            ->where('type', '!=', 'INITIAL')
            ->whereBetween('date', [$from, $to]);
        if ($outletId) {
            $periodMovementsQuery->where('outlet_id', $outletId);
        } elseif ($businessId) {
            $periodMovementsQuery->where('business_id', $businessId);
        }
        $allPeriodMovements = $periodMovementsQuery
            ->orderBy('date', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->groupBy('ingredient_id');

        // Fetch urgent note summaries
        $urgentQuery = UrgentNote::query();
        if ($businessId) {
            $urgentQuery->where('business_id', $businessId);
        }
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

        $inTypes  = ['PURCHASE', 'ADJUSTMENT_IN', 'TRANSFER_IN', 'PREP_OUTPUT', 'ADJUSTMENT_PLUS'];
        $outTypes = ['SALE_USAGE', 'WASTE', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'PREP_USAGE'];

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

        $items = [];
        $grandTotalSafeValuation = 0.0;
        $grandTotalDeficitValuation = 0.0;
        $totalSafeStock = 0;
        $totalNegativeStock = 0;
        $totalEmptyStock = 0;
        $totalLowStock = 0;

        $isHoldingOutlet = $outlet ? ((bool)$outlet->is_main || ((int)$outletId === 1)) : false;

        foreach ($ingredients as $ing) {
            $tanggalSaldoAwalItem = null;
            if (!$outletId) {
                // Consolidated across all branches from Table 2 (ingredient_initial_balances)
                $allInits = \App\Models\IngredientInitialBalance::where('ingredient_id', $ing->id)->get();
                $stokAwalMaster = (float)$allInits->sum('stok_awal');
                $saldoAwalNominalMaster = (float)$allInits->sum('saldo_awal_nominal');
                $stokMinOutlet = (float)$ing->stok_min;
                $hargaBeliOutlet = (float)$ing->harga;
                $tanggalSaldoAwalItem = $allInits->firstWhere('tanggal_saldo_awal', '!=', null)?->tanggal_saldo_awal;
            } else {
                $initRow = \App\Models\IngredientInitialBalance::where('outlet_id', $outletId)->where('ingredient_id', $ing->id)->first();
                $outletRow = $ing->outletIngredients->first();
                $stokAwalMaster = $initRow ? (float)$initRow->stok_awal : 0.0;
                $saldoAwalNominalMaster = $initRow ? (float)$initRow->saldo_awal_nominal : 0.0;
                $tanggalSaldoAwalItem = $initRow?->tanggal_saldo_awal;
                $stokMinOutlet  = $outletRow && $outletRow->stok_min !== null ? (float) $outletRow->stok_min : (float) $ing->stok_min;
                $hargaBeliOutlet = (float)$ing->hargaForOutlet($outletId);
            }

            $konversi = max((float)$ing->konversi, 1);
            $initialCostPerPakai = $stokAwalMaster > 0 ? ($hargaBeliOutlet / $konversi) : 0.0;

            if ($saldoAwalNominalMaster <= 0 && $stokAwalMaster > 0) {
                $saldoAwalNominalMaster = round($stokAwalMaster * ($hargaBeliOutlet / $konversi), 2);
            } elseif ($stokAwalMaster <= 0) {
                $saldoAwalNominalMaster = 0.0;
            }

            // 1. Calculate opening stock & nominal balance strictly before $from (exact match with stockCard)
            $runningStock = $stokAwalMaster;
            $runningNominal = $saldoAwalNominalMaster;
            $currentCostPerPakai = $stokAwalMaster > 0 ? ($saldoAwalNominalMaster / $stokAwalMaster) : 0.0;

            $priorList = $allPriorMovements->get($ing->id) ?? collect();
            foreach ($priorList as $m) {
                $qty = abs((float)$m->qty);
                if (in_array($m->type, $inTypes)) {
                    if ($m->total_price > 0 && $qty > 0) {
                        $inVal = (float)$m->total_price;
                    } elseif ($m->unit_price > 0) {
                        $inVal = $qty * ((float)$m->unit_price / $konversi);
                    } else {
                        $inVal = $qty * $currentCostPerPakai;
                    }
                    $inVal = round($inVal, 2);
                    $runningStock += $qty;
                    $runningNominal = round($runningNominal + $inVal, 2);
                    if ($runningStock > 0) {
                        $currentCostPerPakai = $runningNominal / $runningStock;
                    }
                } else {
                    if ($m->total_price !== null && (float)$m->total_price == 0 && (str_contains($m->note ?? '', 'Defisit') || str_contains($m->note ?? '', 'Nota Urgent'))) {
                        $outVal = 0.0;
                    } elseif ((float)$m->total_price > 0) {
                        $outVal = (float)$m->total_price;
                    } else {
                        $outVal = round($qty * $unitCost, 2);
                    }
                    $outVal = round($outVal, 2);
                    $runningStock -= $qty;
                    $runningNominal = round($runningNominal - $outVal, 2);
                    if ($runningStock > 0) {
                        $currentCostPerPakai = max(0, $runningNominal / $runningStock);
                    }
                }
            }

            $stokAwalPeriod = round($runningStock, 3);
            $nilaiSaldoAwal = round($runningNominal, 2);
            if (abs($nilaiSaldoAwal - round($nilaiSaldoAwal)) < 0.001) {
                $nilaiSaldoAwal = (float)round($nilaiSaldoAwal);
            }
            if ($stokAwalPeriod > 0 && $nilaiSaldoAwal > 0) {
                $currentCostPerPakai = $nilaiSaldoAwal / $stokAwalPeriod;
            } elseif ($currentCostPerPakai <= 0) {
                $currentCostPerPakai = $hargaBeliOutlet / $konversi;
            }

            // 2. Process movements in period (exact match with stockCard)
            $running = $stokAwalPeriod;
            $runningNominal = $nilaiSaldoAwal;
            $totalMasuk = 0.0;
            $totalKeluar = 0.0;
            $totalPenjualan = 0.0;
            $nominalHpp = 0.0;
            $totalWaste = 0.0;
            $nominalWaste = 0.0;
            $totalPembelian = 0.0;
            $totalTransferOut = 0.0;
            $nominalTransferOut = 0.0;
            $totalTransferIn = 0.0;
            $nominalTransferIn = 0.0;
            $nominalMasuk = 0.0;
            $nominalKeluar = 0.0;

            $periodList = $allPeriodMovements->get($ing->id) ?? collect();
            foreach ($periodList as $m) {
                $isOut = in_array($m->type, $outTypes);
                $qty = abs((float)$m->qty);
                $qtyIn = $isOut ? 0 : $qty;
                $qtyOut = $isOut ? $qty : 0;

                if ($isOut) {
                    $totalKeluar += $qtyOut;
                    $running -= $qtyOut;
                    if ($m->type === 'SALE_USAGE' || $m->type === 'PREP_USAGE') {
                        $totalPenjualan += $qtyOut;
                    }

                    if ($m->total_price !== null && (float)$m->total_price == 0 && (str_contains($m->note ?? '', 'Defisit') || str_contains($m->note ?? '', 'Nota Urgent'))) {
                        $outVal = 0.0;
                    } elseif ((float)$m->total_price > 0) {
                        $outVal = (float)$m->total_price;
                    } else {
                        $outVal = round($qtyOut * $unitCost, 2);
                    }
                    $outVal = round($outVal, 2);
                    if ($m->type === 'WASTE') {
                        $totalWaste += $qtyOut;
                        $totalWasteRp += ($m->total_price > 0 ? (float)$m->total_price : $outVal);
                        $nominalWaste = round($nominalWaste + ($m->total_price > 0 ? (float)$m->total_price : $outVal), 2);
                        $totalWasteQty += $qtyOut;
                        $totalWasteCount++;
                    }
                    $runningNominal = round($runningNominal - $outVal, 2);
                    $nominalKeluar = round($nominalKeluar + $outVal, 2);

                    if ($m->type === 'SALE_USAGE' || $m->type === 'PREP_USAGE') {
                        $totalHppRp += $outVal;
                        $nominalHpp = round($nominalHpp + $outVal, 2);
                    } elseif ($m->type === 'TRANSFER_OUT') {
                        $totalTransferOutRp += $outVal;
                        $totalTransferOutQty += $qtyOut;
                        $totalTransferOutCount++;
                        $totalTransferOut += $qtyOut;
                        $nominalTransferOut = round($nominalTransferOut + $outVal, 2);
                    }
                } else {
                    $totalMasuk += $qtyIn;
                    $running += $qtyIn;
                    if ($m->type === 'PURCHASE') {
                        $totalPembelian += $qtyIn;
                    }

                    if ($m->type === 'TRANSFER_IN' && $m->unit_price > 0) {
                        $inVal = round($qtyIn * ((float)$m->unit_price / $konversi), 2);
                    } elseif ($m->total_price > 0 && $qtyIn > 0) {
                        $inVal = (float)$m->total_price;
                    } elseif ($m->unit_price > 0) {
                        $inVal = $qtyIn * ((float)$m->unit_price / $konversi);
                    } else {
                        $inVal = $qtyIn * $currentCostPerPakai;
                    }
                    $inVal = round($inVal, 2);
                    $runningNominal = round($runningNominal + $inVal, 2);
                    $nominalMasuk = round($nominalMasuk + $inVal, 2);
                    $costAfterRow = max(0, $running > 0 ? ($runningNominal / $running) : $currentCostPerPakai);
                    $currentCostPerPakai = $costAfterRow;

                    if ($m->type === 'PURCHASE') {
                        $totalPembelianRp += $inVal;
                    } elseif ($m->type === 'TRANSFER_IN') {
                        $totalTransferInRp += $inVal;
                        $totalTransferInQty += $qtyIn;
                        $totalTransferInCount++;
                        $totalTransferIn += $qtyIn;
                        $nominalTransferIn = round($nominalTransferIn + $inVal, 2);
                    }
                }
            }

            $stokAkhir = round($running, 3);
            $nilaiStok = round($runningNominal, 2);
            if (abs($nilaiStok - round($nilaiStok)) < 0.001) {
                $nilaiStok = (float)round($nilaiStok);
            }

            $nominalMasuk = round($nominalMasuk, 2);
            $nominalKeluar = round($nominalKeluar, 2);

            if ($stokAkhir > 0.0001 && $nilaiStok > 0) {
                $hargaSatuan = round($nilaiStok / $stokAkhir, 2);
            } elseif ($stokAwalPeriod > 0.0001 && $nilaiSaldoAwal > 0) {
                $hargaSatuan = round($nilaiSaldoAwal / $stokAwalPeriod, 2);
            } else {
                $hargaSatuan = 0.0;
            }

            $hargaBeliDisplay = round($hargaSatuan * $konversi, 2);
            if ($hargaBeliDisplay <= 0 && ($stokAkhir > 0.0001 || $stokAwalPeriod > 0.0001)) {
                $hargaBeliDisplay = (float)$hargaBeliOutlet;
            } elseif ($hargaBeliDisplay <= 0) {
                $hargaBeliDisplay = 0.0;
            }

            $saldoAmanRp = $stokAkhir > 0 ? $nilaiStok : 0.0;
            $saldoMinusRp = $stokAkhir < 0 ? $nilaiStok : 0.0; // Negative Rupiah value

            $isNegative = $stokAkhir < -0.0001;
            $isSafe = $stokAkhir > 0.0001;
            $isEmpty = abs($stokAkhir) <= 0.0001;
            $isLow = ($stokAkhir <= $stokMinOutlet && $stokAkhir > 0);

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

            $items[] = [
                'id'                     => $ing->id,
                'code'                   => $ing->code,
                'name'                   => $ing->name,
                'category'               => $ing->category,
                'type'                   => $ing->type ?? 'RAW',
                'unit_pakai'             => $ing->unit_pakai,
                'unit_beli'              => $ing->unit_beli,
                'konversi'               => $konversi,
                'harga_beli'             => (float)$hargaBeliDisplay,
                'harga_satuan'           => $hargaSatuan,
                'stok_min'               => $stokMinOutlet,
                'stok_awal'              => $stokAwalPeriod,
                'nilai_saldo_awal'       => $nilaiSaldoAwal,
                'tanggal_saldo_awal'     => $tanggalSaldoAwalItem,
                'total_masuk'            => round($totalMasuk, 3),
                'total_keluar'           => round($totalKeluar, 3),
                'nominal_masuk'          => $nominalMasuk,
                'nominal_keluar'         => $nominalKeluar,
                'total_penjualan'        => round($totalPenjualan, 3),
                'nominal_hpp'            => round($nominalHpp, 2),
                'total_waste'            => round($totalWaste, 3),
                'nominal_waste'          => round($nominalWaste, 2),
                'total_pembelian'        => round($totalPembelian, 3),
                'total_transfer_out'     => round($totalTransferOut, 3),
                'nominal_transfer_out'   => round($nominalTransferOut, 2),
                'total_transfer_in'      => round($totalTransferIn, 3),
                'nominal_transfer_in'    => round($nominalTransferIn, 2),
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
                'pending_urgent_nominal' => round((float)($pendingUrgentMap[$ing->id] ?? 0) * $hargaSatuan, 2),
                'resolved_urgent_qty'    => (float)($resolvedUrgentMap[$ing->id] ?? 0),
            ];
        }

        // Include any menu waste logs or direct waste logs not linked to a movement (prevent recipe double-counting)
        $menuWasteLogs = WasteLog::whereBetween('date', [$from, $to])
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->when(!$outletId && $businessId, fn($q) => $q->where('business_id', $businessId))
            ->whereNull('stock_movement_id')
            ->whereHas('menu', fn($mq) => $mq->where('item_type', 'DIRECT'))
            ->get();

        foreach ($menuWasteLogs as $mw) {
            $totalWasteRp += (float)$mw->loss_cost;
            $totalWasteQty += (float)$mw->qty_pakai;
            $totalWasteCount++;
        }

        return [
            'items'   => $items,
            'summary' => [
                'total_items'              => count($items),
                'total_safe_items'         => $totalSafeStock,
                'total_negative_items'     => $totalNegativeStock,
                'total_empty_items'        => $totalEmptyStock,
                'total_low_stock'          => $totalLowStock,
                'total_safe_valuation'     => round($grandTotalSafeValuation, 2),
                'total_deficit_valuation'  => round($grandTotalDeficitValuation, 2),
                'net_total_valuation'      => round($grandTotalSafeValuation + $grandTotalDeficitValuation, 2),
                'total_nilai'              => round($grandTotalSafeValuation, 2),
                'total_hpp_rp'             => round($totalHppRp, 2),
                'total_transfer_in_rp'     => round($totalTransferInRp, 2),
                'total_transfer_in_qty'    => round($totalTransferInQty, 2),
                'total_transfer_in_count'  => $totalTransferInCount,
                'total_transfer_out_rp'    => round($totalTransferOutRp, 2),
                'total_transfer_out_qty'   => round($totalTransferOutQty, 2),
                'total_transfer_out_count' => $totalTransferOutCount,
                'total_pembelian_rp'       => round($totalPembelianRp, 2),
                'total_waste_rp'           => round($totalWasteRp, 2),
                'total_waste_qty'          => round($totalWasteQty, 2),
                'total_waste_count'        => $totalWasteCount,
                'total_nilai_saldo_awal_rp'=> round(array_sum(array_column($items, 'nilai_saldo_awal')), 2),
                'total_nominal_masuk_rp'   => round(array_sum(array_column($items, 'nominal_masuk')), 2),
                'total_nominal_keluar_rp'  => round(array_sum(array_column($items, 'nominal_keluar')), 2),
            ],
        ];
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
            'outlet_id'     => 'nullable',
        ]);

        $ingId    = (int) $request->ingredient_id;
        $from     = $request->from;
        $to       = $request->to;
        $user     = $request->user();
        if ($user && ($user->isPegawai() || $user->isOwnerOutlet()) && $user->outlet_id) {
            $outletId = (int)$user->outlet_id;
        } else {
            $rawOut = $request->outlet_id;
            $outletId = ($rawOut && $rawOut !== 'ALL' && $rawOut !== 'all') ? (int)$rawOut : null;
        }

        $ingredient = Ingredient::with(['creator', 'updater', 'outletIngredients.outlet'])->findOrFail($ingId);

        // Opening balance and par level calculation per outlet
        $tanggalSaldoAwal = null;
        if ($outletId) {
            $initRow = \App\Models\IngredientInitialBalance::where('outlet_id', $outletId)->where('ingredient_id', $ingId)->first();
            $outletRow = $ingredient->outletIngredients->firstWhere('outlet_id', $outletId);
            $stokAwalMaster = $initRow ? (float)$initRow->stok_awal : 0.0;
            $saldoAwalNominalMaster = $initRow ? (float)$initRow->saldo_awal_nominal : 0.0;
            $tanggalSaldoAwal = $initRow?->tanggal_saldo_awal;
            $stokMinOutlet  = $outletRow && $outletRow->stok_min !== null ? (float) $outletRow->stok_min : (float) $ingredient->stok_min;
        } else {
            // Consolidated across all branches from Table 2 (ingredient_initial_balances)
            $allInits = \App\Models\IngredientInitialBalance::where('ingredient_id', $ingId)->get();
            $stokAwalMaster = (float) $allInits->sum('stok_awal');
            $saldoAwalNominalMaster = (float) $allInits->sum('saldo_awal_nominal');
            $firstOutRow = $allInits->firstWhere('tanggal_saldo_awal', '!=', null);
            $tanggalSaldoAwal = $firstOutRow?->tanggal_saldo_awal;
            $stokMinOutlet  = (float) $ingredient->stok_min;
        }

        $konversi = max((float)$ingredient->konversi, 1);
        $initialHarga = $outletRow && $outletRow->harga !== null ? (float)$outletRow->harga : (float)$ingredient->harga;
        $initialCostPerPakai = $stokAwalMaster > 0 ? ($initialHarga / $konversi) : 0.0;

        if ($saldoAwalNominalMaster <= 0 && $stokAwalMaster > 0) {
            $saldoAwalNominalMaster = round($stokAwalMaster * ($initialHarga / $konversi), 2);
        } elseif ($stokAwalMaster <= 0) {
            $saldoAwalNominalMaster = 0.0;
        }

        $outTypes = ['SALE_USAGE', 'WASTE', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'PREP_USAGE'];
        $inTypes  = ['PURCHASE', 'ADJUSTMENT_IN', 'TRANSFER_IN', 'PREP_OUTPUT', 'ADJUSTMENT_PLUS'];

        // 1. Calculate historical opening stock & nominal balance strictly before $from (SUM dari saldo awal & mutasi sebelumnya)
        $runningStock = $stokAwalMaster;
        $runningNominal = $saldoAwalNominalMaster;
        $currentCostPerPakai = $stokAwalMaster > 0 ? ($saldoAwalNominalMaster / $stokAwalMaster) : 0.0;

        $priorMovements = StockMovement::where('ingredient_id', $ingId)
            ->where('type', '!=', 'INITIAL')
            ->where('date', '<', $from)
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->orderBy('date', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($priorMovements as $m) {
            $qty = abs((float)$m->qty);
            if (in_array($m->type, $inTypes)) {
                if ($m->total_price > 0 && $qty > 0) {
                    $inVal = (float)$m->total_price;
                } elseif ($m->unit_price > 0) {
                    $inVal = $qty * ((float)$m->unit_price / $konversi);
                } else {
                    $inVal = $qty * $currentCostPerPakai;
                }
                $inVal = round($inVal, 2);
                $runningStock += $qty;
                $runningNominal = round($runningNominal + $inVal, 2);
                if (abs($runningStock) <= 0.0001) {
                    $runningStock = 0.0;
                    $runningNominal = 0.0;
                }
                if ($runningStock > 0) {
                    $currentCostPerPakai = $runningNominal / $runningStock;
                }
            } else {
                $unitCost = $currentCostPerPakai;
                $outVal = ($m->type === 'TRANSFER_OUT' && $m->total_price > 0) ? (float)$m->total_price : round($qty * $unitCost, 2);
                $outVal = round($outVal, 2);
                $runningStock -= $qty;
                $runningNominal = round($runningNominal - $outVal, 2);
                if (abs($runningStock) <= 0.0001) {
                    $runningStock = 0.0;
                    $runningNominal = 0.0;
                } elseif ($runningStock < 0) {
                    $runningNominal = round($runningStock * $currentCostPerPakai, 2);
                }
                if ($runningStock > 0) {
                    $currentCostPerPakai = $runningNominal / $runningStock;
                }
            }
        }

        $stokAwal = round($runningStock, 3);
        $nilaiSaldoAwal = round($runningNominal, 2);
        if (abs($nilaiSaldoAwal - round($nilaiSaldoAwal)) < 0.001) {
            $nilaiSaldoAwal = (float)round($nilaiSaldoAwal);
        }
        if ($stokAwal > 0 && $nilaiSaldoAwal > 0) {
            $currentCostPerPakai = $nilaiSaldoAwal / $stokAwal;
            $costAwalPerPakai = round($currentCostPerPakai, 2);
        } else {
            $costAwalPerPakai = 0.0;
            if ($currentCostPerPakai <= 0) {
                $currentCostPerPakai = $initialHarga / $konversi;
            }
        }

        // Movements in the period ordered chronologically (urutan asli: date, created_at, id)
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
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $running = $stokAwal;
        $runningNominal = $nilaiSaldoAwal;
        $totalMasuk = 0;
        $totalKeluar = 0;
        $totalPenjualan = 0;
        $totalWaste = 0;
        $totalWasteNominal = 0.0;
        $totalPembelian = 0;

        $rows = [];
        foreach ($movements as $m) {
            $isOut = in_array($m->type, $outTypes);
            $qty = abs((float)$m->qty);
            $qtyIn = $isOut ? 0 : $qty;
            $qtyOut = $isOut ? $qty : 0;
            $costBeforeRow = $currentCostPerPakai;

            if ($isOut) {
                $totalKeluar += $qtyOut;
                $running -= $qtyOut;
                if ($m->type === 'SALE_USAGE') $totalPenjualan += $qtyOut;

                $unitCost = $currentCostPerPakai;
                $outVal = ($m->type === 'TRANSFER_OUT' && $m->total_price > 0) ? (float)$m->total_price : round($qtyOut * $unitCost, 2);
                $outVal = round($outVal, 2);
                if ($m->type === 'WASTE') {
                    $totalWaste += $qtyOut;
                    $totalWasteNominal += ($m->total_price > 0 ? (float)$m->total_price : $outVal);
                }
                $runningNominal = round($runningNominal - $outVal, 2);
                if (abs($running) <= 0.0001) {
                    $running = 0.0;
                    $runningNominal = 0.0;
                } elseif ($running < 0) {
                    $runningNominal = round($running * $currentCostPerPakai, 2);
                }
                $costAfterRow = $running > 0 ? ($runningNominal / $running) : $currentCostPerPakai;
                $currentCostPerPakai = $costAfterRow;
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
                $inVal = round($inVal, 2);
                $runningNominal = round($runningNominal + $inVal, 2);
                if (abs($running) <= 0.0001) {
                    $running = 0.0;
                    $runningNominal = 0.0;
                }
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
            if (abs($balanceNominal - round($balanceNominal)) < 0.001) {
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
        if (abs($nilaiStokAkhir - round($nilaiStokAkhir)) < 0.001) {
            $nilaiStokAkhir = (float)round($nilaiStokAkhir);
        }
        $saldoAmanRp = $stokAkhir > 0 ? $nilaiStokAkhir : 0.0;
        $saldoMinusRp = $stokAkhir < 0 ? $nilaiStokAkhir : 0.0;
        $totNominalMasuk = round(array_sum(array_column($rows, 'in_nominal')), 2);
        $totNominalKeluar = round(array_sum(array_column($rows, 'out_nominal')), 2);

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
            'total_nominal_masuk'  => $totNominalMasuk,
            'total_nominal_keluar' => $totNominalKeluar,
            'total_pembelian'      => round($totalPembelian, 3),
            'total_penjualan'            => round($totalPenjualan, 3),
            'total_penjualan_nominal'    => round(array_sum(array_map(fn($r) => ($r['type'] === 'SALE_USAGE' || $r['type'] === 'PREP_USAGE') ? $r['out_nominal'] : 0, $rows)), 2),
            'total_transfer_out'         => round(array_sum(array_map(fn($r) => $r['type'] === 'TRANSFER_OUT' ? $r['qty_out'] : 0, $rows)), 3),
            'total_transfer_out_nominal' => round(array_sum(array_map(fn($r) => $r['type'] === 'TRANSFER_OUT' ? $r['out_nominal'] : 0, $rows)), 2),
            'total_transfer_in'          => round(array_sum(array_map(fn($r) => $r['type'] === 'TRANSFER_IN' ? $r['qty_in'] : 0, $rows)), 3),
            'total_transfer_in_nominal'  => round(array_sum(array_map(fn($r) => $r['type'] === 'TRANSFER_IN' ? $r['in_nominal'] : 0, $rows)), 2),
            'total_waste'                => round($totalWaste, 3),
            'total_waste_nominal'        => round($totalWasteNominal, 2),
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

                // Update OutletIngredient (Table 3: Saldo Berjalan)
                $outletRow = OutletIngredient::firstOrNew([
                    'outlet_id'     => $targetOutlet->id,
                    'ingredient_id' => $ingredient->id,
                ]);
                if ($minStock !== null) {
                    $outletRow->stok_min = $minStock;
                }
                if ($hargaBeli > 0) {
                    $outletRow->harga = $hargaBeli;
                    $outletRow->last_purchase_price = $hargaBeli;
                }
                $outletRow->save();

                // Save to Table 2 (IngredientInitialBalance - Saldo Awal)
                \App\Models\IngredientInitialBalance::updateOrCreate(
                    [
                        'outlet_id'     => $targetOutlet->id,
                        'ingredient_id' => $ingredient->id,
                    ],
                    [
                        'business_id'        => $ingredient->business_id,
                        'stok_awal'          => round($qtyPakai, 3),
                        'saldo_awal_nominal' => round($totalPriceVal, 2),
                        'tanggal_saldo_awal' => $effectiveDate,
                        'updated_by'         => auth()->id(),
                    ]
                );

                // Note: stok_min is an outlet-level operational field stored in outlet_ingredients (Table 3), not on ingredients table.
                if ($minStock !== null && $mainOutlet && (int)$mainOutlet->id !== (int)$targetOutlet->id) {
                    $mainOi = OutletIngredient::firstOrNew([
                        'outlet_id'     => $mainOutlet->id,
                        'ingredient_id' => $ingredient->id,
                    ]);
                    if ($mainOi->stok_min === null || (float)$mainOi->stok_min == 0) {
                        $mainOi->stok_min = $minStock;
                        $mainOi->save();
                    }
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

        if ($businessId && $importedCount > 0) {
            try {
                $biz = \App\Models\Business::find($businessId);
                if ($biz) {
                    AccountingService::postInitialBalance($biz);
                }
            } catch (\Throwable $accEx) {
                try {
                    \Illuminate\Support\Facades\Log::error("postInitialBalance after import error: " . $accEx->getMessage());
                } catch (\Throwable $lEx) {}
            }
        }
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
        $paymentType = in_array($rawPaymentType, ['HUTANG', 'TEMPO', 'CREDIT']) ? 'HUTANG' : ($rawPaymentType ?: 'CASH');

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

                $ingKonversi = max(1, (float)($movement->ingredient?->konversi ?: 1));
                foreach ($pairedMovements as $pm) {
                    $pm->date        = $validated['date'];
                    if ($pm->type === 'TRANSFER_IN' || $pm->type === 'WASTE') {
                        // Jangan timpa qty jika penerimaan parsial atau retur waste, hitung total_price secara proporsional
                        $pmQty = (float)$pm->qty;
                        $pm->unit_price  = $pricePerBeli;
                        $pm->total_price = round(($pmQty / $ingKonversi) * $pricePerBeli, 2);
                    } else {
                        $pm->qty         = $qtyPakai;
                        $pm->unit_price  = $pricePerBeli;
                        $pm->total_price = $totalPrice;
                    }
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

        // Auto-update double-entry journal for purchase
        if (in_array($movement->type, ['PURCHASE', 'RESTOCK', 'IN']) && (float)$movement->total_price > 0) {
            AccountingService::postStockMovementPurchase($movement);
        }

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

        try {
            TransferController::syncMissingWasteMovements($user->business_id);
        } catch (\Throwable $e) {}

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

            $targetOutlet = $outletId ? Outlet::find($outletId) : null;
            $isHoldingTarget = $targetOutlet ? ((bool)$targetOutlet->is_main || (int)$targetOutlet->id === 1) : false;

            foreach ($affectedOutletIngredients as $oi) {
                if (!$keepInitial) {
                    $oi->current_stock = 0;
                } else {
                    $initBal = \App\Models\IngredientInitialBalance::where('outlet_id', $oi->outlet_id)
                        ->where('ingredient_id', $oi->ingredient_id)
                        ->first();
                    $oi->current_stock = $initBal ? (float)$initBal->stok_awal : 0;
                }
                $oi->save();
            }

            if (!$keepInitial) {
                // Table 2: Reset Saldo Awal
                $initQuery = \App\Models\IngredientInitialBalance::query();
                if ($businessId) $initQuery->where('business_id', $businessId);
                if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') $initQuery->where('outlet_id', $outletId);
                if ($scope === 'SELECTED_INGREDIENT') $initQuery->where('ingredient_id', $ingredientId);
                $initQuery->update(['stok_awal' => 0, 'saldo_awal_nominal' => 0, 'tanggal_saldo_awal' => null]);
            }



            // Recompute / sync Moving Average HPP hanya untuk cabang/bahan yang terpengaruh
            if ($scope === 'SELECTED_INGREDIENT') {
                $ing = Ingredient::find($ingredientId);
                if ($ing) {
                    $visited = [];
                    if ($outletId && $outletId !== 'ALL' && $outletId !== 'all') {
                        $ing->recomputeMovingAverageFromHistory((int)$outletId, $visited);
                    } else {
                        $outlets = $businessId ? Outlet::where('business_id', $businessId)->get() : Outlet::all();
                        foreach ($outlets as $o) {
                            $ing->recomputeMovingAverageFromHistory((int)$o->id, $visited);
                        }
                    }
                }
            } elseif ($scope === 'CURRENT_OUTLET') {
                // Hanya recompute cabang yang di-reset agar tidak mempengaruhi/menghapus data cabang lain
                $allIngs = $businessId ? Ingredient::where('business_id', $businessId)->get() : Ingredient::all();
                foreach ($allIngs as $ing) {
                    $visited = [];
                    $ing->recomputeMovingAverageFromHistory((int)$outletId, $visited);
                }
            } else {
                // Seluruh cabang bisnis
                $allIngs = $businessId ? Ingredient::where('business_id', $businessId)->get() : Ingredient::all();
                $outlets = $businessId ? Outlet::where('business_id', $businessId)->get() : Outlet::all();
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

    /**
     * Cari mutasi tempat stok MULAI minus pada rentetan minus yang masih berlangsung saat ini
     * (berdasarkan urutan kronologis asli: date, created_at, id).
     * Dipakai untuk opsi "Lupa Input": barang masuk disisipkan tepat di atas mutasi ini
     * agar kartu stok kembali normal sejak titik minus tersebut.
     */
    private function findEarliestNegativeMovement(int $ingredientId, int $outletId): ?StockMovement
    {
        $ing = Ingredient::find($ingredientId);
        if (!$ing) return null;

        $initRow = \App\Models\IngredientInitialBalance::where('outlet_id', $outletId)->where('ingredient_id', $ingredientId)->first();
        $initialStock = $initRow ? (float)$initRow->stok_awal : 0.0;

        $movements = StockMovement::where('ingredient_id', $ingredientId)
            ->where('outlet_id', $outletId)
            ->where('type', '!=', 'INITIAL')
            ->orderBy('date', 'asc')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $running = $initialStock;
        $inTypes = ['PURCHASE', 'ADJUSTMENT_IN', 'TRANSFER_IN', 'PREP_OUTPUT', 'ADJUSTMENT_PLUS'];

        $streakStart = null;

        foreach ($movements as $m) {
            $qty = (float)$m->qty;
            if (in_array($m->type, $inTypes)) {
                $running += $qty;
            } else {
                $running -= $qty;
            }

            if ($running < -0.0001) {
                // Titik awal minus: saldo sebelumnya >= 0 lalu menjadi minus di mutasi ini
                if (!$streakStart) {
                    $streakStart = $m;
                }
            } else {
                // Saldo kembali normal (>= 0) -> minus sebelumnya sudah tertutup
                $streakStart = null;
            }
        }

        return $streakStart;
    }

}

