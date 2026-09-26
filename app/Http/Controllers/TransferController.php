<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\StockMovement;
use App\Models\Outlet;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\OutletMenu;
use App\Models\WasteLog;
use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransferController extends Controller
{
    public function index(Request $request)
    {
        $query = Transfer::with([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'items.menu',
            'creator',
            'updater',
            'receiver',
            'returner',
            'returnApprover',
            'returnRejecter',
            'stockMovements'
        ])
        ->orderByDesc('date')
        ->orderByDesc('id');

        $user = $request->user();
        if ($user && !$user->isPlatformAdmin() && !$user->isOwnerBisnis() && $user->outlet_id) {
            $userOutletId = (int)$user->outlet_id;
            $query->where(function($q) use ($userOutletId) {
                $q->where('source_outlet_id', $userOutletId)
                  ->orWhere('destination_outlet_id', $userOutletId);
            });
        }

        if ($request->source_outlet_id) {
            $query->where('source_outlet_id', $request->source_outlet_id);
        }
        if ($request->destination_outlet_id) {
            $query->where('destination_outlet_id', $request->destination_outlet_id);
        }

        if ($request->from) {
            $query->where('date', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('date', '<=', $request->to);
        }
        if ($request->status) {
            if (str_contains($request->status, ',')) {
                $statuses = array_filter(array_map('trim', explode(',', $request->status)));
                $query->whereIn('status', $statuses);
            } else {
                $query->where('status', $request->status);
            }
        }
        if ($request->return_status) {
            $query->where('return_status', $request->return_status);
        }

        if ($request->user()?->business_id) {
            $query->where('business_id', $request->user()->business_id);
        }

        return response()->json($query->limit(200)->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'date'                  => 'required|date',
            'source_type'           => 'nullable|string|in:OUTLET,WAREHOUSE,EXTERNAL',
            'source_outlet_id'      => 'nullable|exists:outlets,id',
            'source_name'           => 'nullable|string|max:150',
            'destination_type'      => 'nullable|string|in:OUTLET,WAREHOUSE,EXTERNAL',
            'destination_outlet_id' => 'nullable|exists:outlets,id',
            'destination_name'      => 'nullable|string|max:150',
            'transfer_type'         => 'nullable|string|in:INTER_OUTLET,INBOUND,OUTBOUND,EXTERNAL',
            'status'                => 'nullable|string|in:IN_TRANSIT,PENDING,COMPLETED',
            'notes'                 => 'nullable|string|max:500',
            'driver_name'           => 'nullable|string|max:100',
            'vehicle_no'            => 'nullable|string|max:50',
            'items'                 => 'required|array|min:1',
            'items.*.item_type'     => 'nullable|string|in:INGREDIENT,PRODUCT,PERLENGKAPAN',
            'items.*.ingredient_id' => 'nullable|exists:ingredients,id',
            'items.*.menu_id'       => 'nullable|exists:menus,id',
            'items.*.qty'           => 'nullable|numeric|min:0.0001',
            'items.*.unit'          => 'nullable|string|max:30',
            'items.*.input_qty'     => 'nullable|numeric|min:0.0001',
            'items.*.input_unit'    => 'nullable|string|max:30',
            'items.*.unit_price'    => 'nullable|numeric|min:0',
            'items.*.total_price'   => 'nullable|numeric|min:0',
            'items.*.notes'         => 'nullable|string|max:255',
            'payment_type'          => 'nullable|string|in:INTERNAL,CASH,BANK,TRANSFER,QRIS,HUTANG',
            'payment_method'        => 'nullable|string|max:50',
            'supplier_name'         => 'nullable|string|max:150',
            'purchase_no'           => 'nullable|string|max:100',
            'due_date'              => 'nullable|date',
            'initial_paid'          => 'nullable|numeric|min:0',
        ], [
            'items.min' => 'Pilih minimal satu barang atau bahan untuk ditransfer.',
        ]);

        // Verifikasi lokasi asal dan tujuan
        $hasSource = !empty($validated['source_outlet_id']) || !empty(trim($validated['source_name'] ?? ''));
        $hasDest = !empty($validated['destination_outlet_id']) || !empty(trim($validated['destination_name'] ?? ''));

        if (!$hasSource) {
            return response()->json(['message' => 'Tentukan cabang/lokasi asal pengirim barang.'], 422);
        }
        if (!$hasDest) {
            return response()->json(['message' => 'Tentukan cabang/lokasi tujuan penerima barang.'], 422);
        }

        $sourceOutletId = !empty($validated['source_outlet_id']) ? (int)$validated['source_outlet_id'] : null;
        $destOutletId   = !empty($validated['destination_outlet_id']) ? (int)$validated['destination_outlet_id'] : null;

        if ($sourceOutletId && $destOutletId && $sourceOutletId === $destOutletId) {
            return response()->json(['message' => 'Cabang tujuan tidak boleh sama dengan cabang pengirim.'], 422);
        }

        $user = $request->user();
        $userBusinessId = $user?->business_id;

        $sourceOutlet = $sourceOutletId ? Outlet::find($sourceOutletId) : null;
        $destOutlet   = $destOutletId ? Outlet::find($destOutletId) : null;

        if ($sourceOutletId && !$sourceOutlet) {
            return response()->json(['message' => 'Cabang asal tidak ditemukan.'], 404);
        }
        if ($destOutletId && !$destOutlet) {
            return response()->json(['message' => 'Cabang tujuan tidak ditemukan.'], 404);
        }

        // Kunci ketat: Transfer HANYA bisa dilakukan antar cabang di dalam satu perusahaan yang sama
        if ($userBusinessId) {
            if ($sourceOutlet && (int)$sourceOutlet->business_id !== (int)$userBusinessId) {
                return response()->json(['message' => 'Cabang asal bukan milik perusahaan Anda.'], 403);
            }
            if ($destOutlet && (int)$destOutlet->business_id !== (int)$userBusinessId) {
                return response()->json(['message' => 'Cabang tujuan bukan milik perusahaan Anda.'], 403);
            }
        }

        // Kunci penempatan pegawai/outlet: pegawai hanya berhak membuat transfer jika cabang penempatannya adalah asal atau tujuan
        if ($user && !$user->isPlatformAdmin() && !$user->isOwnerBisnis() && $user->outlet_id) {
            $myOutletId = (int)$user->outlet_id;
            if ($sourceOutletId !== $myOutletId && $destOutletId !== $myOutletId) {
                return response()->json([
                    'message' => 'Anda hanya berhak membuat transfer yang melibatkan cabang penempatan Anda (' . ($user->outlet?->name ?? 'Outlet Anda') . ').'
                ], 403);
            }
        }

        if ($sourceOutlet && $destOutlet && (int)$sourceOutlet->business_id !== (int)$destOutlet->business_id) {
            return response()->json(['message' => 'Transfer hanya dapat dilakukan antar cabang dalam satu perusahaan yang sama.'], 422);
        }

        // Validasi ketersediaan stok di cabang asal: Tidak boleh transfer jika stok bahan atau produk kurang
        if ($sourceOutlet) {
            $deficitErrors = [];
            $neededIngredients = [];
            $neededProducts = [];

            foreach ($validated['items'] as $itemData) {
                $itemType = strtoupper($itemData['item_type'] ?? 'INGREDIENT');

                if ($itemType === 'PRODUCT' || (!empty($itemData['menu_id']) && empty($itemData['ingredient_id']))) {
                    $menuId = (int)$itemData['menu_id'];
                    $qty = isset($itemData['input_qty']) && $itemData['input_qty'] !== ''
                        ? (float)$itemData['input_qty']
                        : (float)($itemData['qty'] ?? 1);

                    if (!isset($neededProducts[$menuId])) {
                        $menu = Menu::find($menuId);
                        $neededProducts[$menuId] = [
                            'menu'      => $menu,
                            'total_qty' => 0.0,
                        ];
                    }
                    $neededProducts[$menuId]['total_qty'] += $qty;
                } else {
                    $ingredientId = (int)($itemData['ingredient_id'] ?? 0);
                    if (!$ingredientId) {
                        continue;
                    }
                    $ingredient = Ingredient::find($ingredientId);
                    if (!$ingredient) {
                        continue;
                    }

                    $inputQty = isset($itemData['input_qty']) && $itemData['input_qty'] !== ''
                        ? (float)$itemData['input_qty']
                        : (float)($itemData['qty'] ?? 0);

                    $inputUnit = !empty($itemData['input_unit'])
                        ? trim($itemData['input_unit'])
                        : (!empty($itemData['unit']) ? trim($itemData['unit']) : (string)$ingredient->unit_pakai);

                    $konversi = (float)($ingredient->konversi ?: 1);
                    $isUnitBeli = strtolower($inputUnit) === strtolower((string)$ingredient->unit_beli);
                    $canConvert = $isUnitBeli && strtolower((string)$ingredient->unit_beli) !== strtolower((string)$ingredient->unit_pakai) && $konversi > 1;

                    $baseQty = $canConvert ? round($inputQty * $konversi, 4) : round($inputQty, 4);

                    if (!isset($neededIngredients[$ingredientId])) {
                        $neededIngredients[$ingredientId] = [
                            'ingredient'     => $ingredient,
                            'total_base_qty' => 0.0,
                        ];
                    }
                    $neededIngredients[$ingredientId]['total_base_qty'] += $baseQty;
                }
            }

            // Cek ketersediaan stok bahan baku di cabang asal
            foreach ($neededIngredients as $ingId => $data) {
                $ing = $data['ingredient'];
                $neededBaseQty = $data['total_base_qty'];
                $availableStock = (float)$ing->stockForOutlet($sourceOutlet->id);

                if (round($neededBaseQty - $availableStock, 4) > 0.0001) {
                    $shortfall = round($neededBaseQty - $availableStock, 4);
                    $unitPakai = (string)($ing->unit_pakai ?: 'satuan');
                    $availFmt = number_format($availableStock, 2, ',', '.');
                    $neededFmt = number_format($neededBaseQty, 2, ',', '.');
                    $shortfallFmt = number_format($shortfall, 2, ',', '.');

                    $deficitErrors[] = "Stok bahan '{$ing->name}' di cabang asal ({$sourceOutlet->name}) tidak mencukupi. Tersedia: {$availFmt} {$unitPakai}, Dibutuhkan: {$neededFmt} {$unitPakai} (Kurang {$shortfallFmt} {$unitPakai}).";
                }
            }

            // Cek ketersediaan stok produk retail di cabang asal
            foreach ($neededProducts as $mId => $data) {
                $menu = $data['menu'];
                if ($menu && $menu->track_stock) {
                    $neededQty = $data['total_qty'];
                    $availableStock = (float)$menu->stockForOutlet($sourceOutlet->id);

                    if (round($neededQty - $availableStock, 4) > 0.0001) {
                        $shortfall = round($neededQty - $availableStock, 4);
                        $unit = (string)($menu->unit ?: 'pcs');
                        $availFmt = number_format($availableStock, 2, ',', '.');
                        $neededFmt = number_format($neededQty, 2, ',', '.');
                        $shortfallFmt = number_format($shortfall, 2, ',', '.');

                        $deficitErrors[] = "Stok produk '{$menu->name}' di cabang asal ({$sourceOutlet->name}) tidak mencukupi. Tersedia: {$availFmt} {$unit}, Dibutuhkan: {$neededFmt} {$unit} (Kurang {$shortfallFmt} {$unit}).";
                    }
                }
            }

            if (!empty($deficitErrors)) {
                return response()->json([
                    'message' => 'Transfer ditolak: Stok bahan atau produk di cabang asal tidak mencukupi.',
                    'errors'  => $deficitErrors,
                ], 422);
            }
        }

        $businessIdToAssign = $userBusinessId ?? $sourceOutlet?->business_id ?? $destOutlet?->business_id;

        $initialStatus = $validated['status'] ?? 'IN_TRANSIT';

        $transfer = DB::transaction(function () use ($request, $validated, $sourceOutlet, $destOutlet, $businessIdToAssign, $initialStatus) {
            $date = $validated['date'];
            $dateClean = str_replace('-', '', $date);
            $countQuery = Transfer::whereDate('date', $date);
            if ($businessIdToAssign) {
                $countQuery->where('business_id', $businessIdToAssign);
            }
            $countToday = $countQuery->count() + 1;
            $transferNo = 'TRF-' . $dateClean . '-' . str_pad($countToday, 4, '0', STR_PAD_LEFT);

            $sourceName = $sourceOutlet ? $sourceOutlet->name : trim($validated['source_name'] ?? 'Cabang Asal');
            $destName   = $destOutlet ? $destOutlet->name : trim($validated['destination_name'] ?? 'Cabang Tujuan');

            // Tentukan transfer_type otomatis jika tidak diset
            $transferType = $validated['transfer_type'] ?? null;
            if (!$transferType) {
                if ($sourceOutlet && $destOutlet) {
                    $transferType = 'INTER_OUTLET';
                } elseif (!$sourceOutlet && $destOutlet) {
                    $transferType = 'INBOUND';
                } elseif ($sourceOutlet && !$destOutlet) {
                    $transferType = 'OUTBOUND';
                } else {
                    $transferType = 'EXTERNAL';
                }
            }

            $userId = $request->user()?->id;

            $rawPaymentType = strtoupper(trim($validated['payment_type'] ?? ($sourceOutlet ? 'INTERNAL' : 'CASH')));
            $isHutang = in_array($rawPaymentType, ['HUTANG', 'TEMPO']);
            $paymentType = $isHutang ? 'HUTANG' : $rawPaymentType;

            $transfer = Transfer::create([
                'business_id'           => $businessIdToAssign,
                'transfer_no'           => $transferNo,
                'date'                  => $date,
                'source_type'           => $sourceOutlet ? 'OUTLET' : ($validated['source_type'] ?? 'EXTERNAL'),
                'source_name'           => $sourceName,
                'source_outlet_id'      => $sourceOutlet?->id,
                'destination_type'      => $destOutlet ? 'OUTLET' : ($validated['destination_type'] ?? 'EXTERNAL'),
                'destination_name'      => $destName,
                'destination_outlet_id' => $destOutlet?->id,
                'transfer_type'         => $transferType,
                'status'                => $initialStatus,
                'payment_type'          => $paymentType,
                'payment_method'        => $validated['payment_method'] ?? null,
                'supplier_name'         => $validated['supplier_name'] ?? null,
                'purchase_no'           => $validated['purchase_no'] ?? null,
                'due_date'              => $validated['due_date'] ?? null,
                'notes'                 => $validated['notes'] ?? null,
                'driver_name'           => $validated['driver_name'] ?? null,
                'vehicle_no'            => $validated['vehicle_no'] ?? null,
                'total_items'           => count($validated['items']),
                'created_by'            => $userId,
                'updated_by'            => $userId,
            ]);

            foreach ($validated['items'] as $itemData) {
                $itemType = strtoupper($itemData['item_type'] ?? 'INGREDIENT');

                // Jika item adalah PRODUK RETAIL / JADI (DIRECT MENU)
                if ($itemType === 'PRODUCT' || (!empty($itemData['menu_id']) && empty($itemData['ingredient_id']))) {
                    $menu = Menu::findOrFail($itemData['menu_id']);
                    $qty = isset($itemData['input_qty']) && $itemData['input_qty'] !== ''
                        ? (float)$itemData['input_qty']
                        : (float)($itemData['qty'] ?? 1);
                    $unit = !empty($itemData['input_unit']) ? trim($itemData['input_unit']) : ($menu->unit ?: 'pcs');

                    $prodUnitPrice = isset($itemData['unit_price']) && $itemData['unit_price'] !== '' ? (float)$itemData['unit_price'] : null;
                    $prodTotalPrice = isset($itemData['total_price']) && $itemData['total_price'] !== '' ? (float)$itemData['total_price'] : null;

                    $isInternal = ($transfer->payment_type ?? 'INTERNAL') === 'INTERNAL';
                    if ($isInternal || $prodUnitPrice === null) {
                        $sourceCost = $sourceOutlet ? $menu->costPriceForOutlet($sourceOutlet->id) : (float)($menu->cost_price ?: ($menu->cost ?: $menu->price));
                        $prodUnitPrice = $sourceCost;
                        $prodTotalPrice = round($qty * $prodUnitPrice, 2);
                    }

                    TransferItem::create([
                        'transfer_id' => $transfer->id,
                        'item_type'   => 'PRODUCT',
                        'menu_id'     => $menu->id,
                        'qty'         => $qty,
                        'unit'        => $unit,
                        'input_qty'   => $qty,
                        'input_unit'  => $unit,
                        'unit_price'  => $prodUnitPrice,
                        'total_price' => $prodTotalPrice,
                        'notes'       => $itemData['notes'] ?? null,
                    ]);

                    // Mutasi potong stok retail outlet asal (langsung dipotong saat dikirim/diproses)
                    if ($sourceOutlet && $menu->track_stock) {
                        try {
                            if (Schema::hasTable('outlet_menus')) {
                                $sourceOm = OutletMenu::firstOrCreate(
                                    ['outlet_id' => $sourceOutlet->id, 'menu_id' => $menu->id],
                                    ['stock' => $menu->stock, 'min_stock' => $menu->min_stock]
                                );
                                $sourceOm->decrement('stock', $qty);
                            }
                        } catch (\Throwable $e) {}
                        $menu->decrement('stock', $qty);
                    }

                    // Mutasi tambah stok retail outlet tujuan (hanya jika langsung status COMPLETED)
                    if ($initialStatus === 'COMPLETED' && $destOutlet && $menu->track_stock) {
                        try {
                            if (Schema::hasTable('outlet_menus')) {
                                $destOm = OutletMenu::firstOrCreate(
                                    ['outlet_id' => $destOutlet->id, 'menu_id' => $menu->id],
                                    ['stock' => 0, 'min_stock' => $menu->min_stock]
                                );
                                $destOm->increment('stock', $qty);

                                if ($prodUnitPrice !== null && (float)$prodUnitPrice > 0) {
                                    $destOm->cost_price = (float)$prodUnitPrice;
                                    $destOm->save();
                                } elseif ($sourceOutlet) {
                                    $sourceOm = OutletMenu::where('outlet_id', $sourceOutlet->id)->where('menu_id', $menu->id)->first();
                                    $sourceCost = $sourceOm?->cost_price ?? $menu->cost_price;
                                    if ($sourceCost !== null && (float)$sourceCost > 0) {
                                        $destOm->cost_price = (float)$sourceCost;
                                        $destOm->save();
                                    }
                                }
                            }
                        } catch (\Throwable $e) {}
                        $menu->increment('stock', $qty);
                    }

                } else {
                    // Item adalah BAHAN BAKU (INGREDIENT)
                    $ingredient = Ingredient::findOrFail($itemData['ingredient_id']);

                    $inputQty = isset($itemData['input_qty']) && $itemData['input_qty'] !== ''
                        ? (float) $itemData['input_qty']
                        : (float) ($itemData['qty'] ?? 0);

                    $inputUnit = !empty($itemData['input_unit'])
                        ? trim($itemData['input_unit'])
                        : (!empty($itemData['unit']) ? trim($itemData['unit']) : (string)$ingredient->unit_pakai);

                    $konversi = (float) ($ingredient->konversi ?: 1);
                    $isUnitBeli = strtolower($inputUnit) === strtolower((string)$ingredient->unit_beli);
                    $canConvert = $isUnitBeli && strtolower((string)$ingredient->unit_beli) !== strtolower((string)$ingredient->unit_pakai) && $konversi > 1;

                    if ($canConvert) {
                        $baseQty = round($inputQty * $konversi, 4);
                        $baseUnit = (string) $ingredient->unit_pakai;
                    } else {
                        $baseQty = round($inputQty, 4);
                        $baseUnit = (string) ($ingredient->unit_pakai ?: $inputUnit);
                    }

                    // Hitung harga modal/transfer real-time dari cabang asal (Moving Average)
                    $sourcePricePerBeli = $sourceOutlet ? $ingredient->hargaForOutlet($sourceOutlet->id) : (float)$ingredient->harga;
                    $sourcePricePerPakai = $sourcePricePerBeli / max((float)$ingredient->konversi, 1);
                    $itemTotalPrice = round(($baseQty / max((float)$ingredient->konversi, 1)) * $sourcePricePerBeli, 2);

                    $ingUnitPrice = isset($itemData['unit_price']) && $itemData['unit_price'] !== '' ? (float)$itemData['unit_price'] : null;
                    $ingTotalPrice = isset($itemData['total_price']) && $itemData['total_price'] !== '' ? (float)$itemData['total_price'] : null;

                    if ($ingUnitPrice === null) {
                        $ingUnitPrice = $isUnitBeli ? $sourcePricePerBeli : $sourcePricePerPakai;
                        $ingTotalPrice = $itemTotalPrice;
                    } elseif ($ingTotalPrice === null && $ingUnitPrice !== null) {
                        $ingTotalPrice = round($inputQty * $ingUnitPrice, 2);
                    }

                    TransferItem::create([
                        'transfer_id'   => $transfer->id,
                        'item_type'     => in_array($itemType, ['PERLENGKAPAN', 'INGREDIENT']) ? $itemType : 'INGREDIENT',
                        'ingredient_id' => $ingredient->id,
                        'qty'           => $baseQty,
                        'unit'          => $baseUnit,
                        'input_qty'     => $inputQty,
                        'input_unit'    => $inputUnit,
                        'unit_price'    => $ingUnitPrice,
                        'total_price'   => $ingTotalPrice,
                        'notes'         => $itemData['notes'] ?? null,
                    ]);

                    $noteSuffix = $canConvert ? " ({$inputQty} {$inputUnit} ≈ " . number_format($baseQty, 0, ',', '.') . " {$baseUnit})" : "";

                    // 1. Mutasi TRANSFER_OUT dari outlet asal (langsung terpotong saat dikirim)
                    if ($sourceOutlet) {
                        StockMovement::create([
                            'date'          => $date,
                            'ingredient_id' => $ingredient->id,
                            'type'          => 'TRANSFER_OUT',
                            'qty'           => $baseQty,
                            'unit_price'    => $sourcePricePerBeli,
                            'total_price'   => $itemTotalPrice,
                            'cost_before'   => $sourcePricePerPakai,
                            'cost_after'    => $sourcePricePerPakai,
                            'note'          => "Transfer keluar ke {$destName}{$noteSuffix} ({$transferNo})",
                            'transfer_id'   => $transfer->id,
                            'outlet_id'     => $sourceOutlet->id,
                            'user_id'       => $userId,
                            'created_by'    => $userId,
                        ]);
                    }

                    // 2. Mutasi TRANSFER_IN ke outlet tujuan (hanya jika langsung status COMPLETED)
                    if ($initialStatus === 'COMPLETED' && $destOutlet) {
                        // Cabang penerima menyerap stok masuk dengan harga transfer dari cabang asal
                        $destAvg = $ingredient->recalculateMovingAverage($baseQty, $sourcePricePerPakai, $destOutlet->id);

                        StockMovement::create([
                            'date'          => $date,
                            'ingredient_id' => $ingredient->id,
                            'type'          => 'TRANSFER_IN',
                            'qty'           => $baseQty,
                            'unit_price'    => $sourcePricePerBeli,
                            'total_price'   => $itemTotalPrice,
                            'cost_before'   => $destAvg['cost_before'],
                            'cost_after'    => $destAvg['cost_after'],
                            'note'          => "Transfer masuk dari {$sourceName}{$noteSuffix} ({$transferNo})",
                            'transfer_id'   => $transfer->id,
                            'outlet_id'     => $destOutlet->id,
                            'user_id'       => $userId,
                            'created_by'    => $userId,
                        ]);
                    }
                }
            }

            // Hitung total nilai transfer dari seluruh item
            $totalTransferAmount = (float)TransferItem::where('transfer_id', $transfer->id)->sum('total_price');

            $rawPaymentType = strtoupper(trim($validated['payment_type'] ?? 'INTERNAL'));
            $isHutang = in_array($rawPaymentType, ['HUTANG', 'TEMPO']);
            $paymentType = $isHutang ? 'HUTANG' : $rawPaymentType;
            $initialPaid = (float)($validated['initial_paid'] ?? 0);
            $payableId = null;

            if ($isHutang && $totalTransferAmount > 0) {
                $suppName = trim($validated['supplier_name'] ?? ($sourceOutlet ? $sourceOutlet->name : $sourceName));
                $supp = Supplier::firstOrCreate(
                    ['business_id' => $businessIdToAssign, 'name' => $suppName],
                    ['active' => true]
                );

                $paidAmt = min($initialPaid, $totalTransferAmount);
                $remAmt = max(0, $totalTransferAmount - $paidAmt);
                $payStatus = ($remAmt <= 0) ? 'PAID' : ($paidAmt > 0 ? 'PARTIAL' : 'UNPAID');

                $payable = Payable::create([
                    'payable_no'       => Payable::generatePayableNo($businessIdToAssign, $date),
                    'purchase_no'      => $validated['purchase_no'] ?? $transferNo,
                    'business_id'      => $businessIdToAssign,
                    'outlet_id'        => $destOutletId ?? $sourceOutletId,
                    'supplier_id'      => $supp->id,
                    'supplier_name'    => $suppName,
                    'issue_date'       => $date,
                    'due_date'         => $validated['due_date'] ?? Carbon::parse($date)->addDays(30)->toDateString(),
                    'total_amount'     => $totalTransferAmount,
                    'paid_amount'      => $paidAmt,
                    'remaining_amount' => $remAmt,
                    'status'           => $payStatus,
                    'notes'            => "Transfer / Pengadaan Stok: {$transferNo} ({$transfer->total_items} item)",
                    'created_by'       => $userId,
                ]);

                $payableId = $payable->id;

                if ($paidAmt > 0) {
                    PayablePayment::create([
                        'payment_no'     => PayablePayment::generatePaymentNo($businessIdToAssign, $date),
                        'payable_id'     => $payable->id,
                        'business_id'    => $businessIdToAssign,
                        'outlet_id'      => $destOutletId ?? $sourceOutletId,
                        'payment_date'   => $date,
                        'amount'         => $paidAmt,
                        'payment_method' => $validated['payment_method'] ?? 'CASH',
                        'notes'          => "Uang Muka (DP) Transfer {$transferNo}",
                        'paid_by'        => $userId,
                    ]);
                }
            }

            $transfer->update([
                'payment_type'   => $paymentType,
                'payment_method' => $validated['payment_method'] ?? null,
                'total_amount'   => $totalTransferAmount,
                'supplier_name'  => $validated['supplier_name'] ?? null,
                'purchase_no'    => $validated['purchase_no'] ?? null,
                'due_date'       => $validated['due_date'] ?? null,
                'initial_paid'   => $initialPaid,
                'payable_id'     => $payableId,
            ]);

            return $transfer;
        });

        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'items.menu',
            'creator',
            'updater',
            'receiver',
            'payable',
            'stockMovements'
        ]);

        return response()->json($transfer, 201);
    }

    public function show(Transfer $transfer)
    {
        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'items.menu',
            'creator',
            'updater',
            'receiver',
            'stockMovements'
        ]);
        return response()->json($transfer);
    }

    /**
     * Fitur Receive / Terima Transfer Barang di Cabang Tujuan
     */
    public function receive(Request $request, Transfer $transfer)
    {
        if ($transfer->status === 'COMPLETED' || $transfer->status === 'PARTIALLY_RETURNED' || $transfer->status === 'RETURNED') {
            return response()->json(['message' => 'Transfer ini sudah diproses penerimaannya sebelumnya.'], 422);
        }
        if ($transfer->status === 'CANCELLED') {
            return response()->json(['message' => 'Transfer ini sudah dibatalkan dan tidak dapat diterima.'], 422);
        }

        $validated = $request->validate([
            'received_notes'     => 'nullable|string|max:500',
            'return_disposition' => 'nullable|string|in:RECORD_AS_WASTE,RETURN_TO_SOURCE',
            'return_reason'      => 'nullable|string|max:255',
            'items'              => 'nullable|array',
            'items.*.id'         => 'required_with:items|exists:transfer_items,id',
            'items.*.received_qty' => 'required_with:items|numeric|min:0',
            'items.*.reason'     => 'nullable|string|max:255',
        ]);

        $destOutlet  = $transfer->destinationOutlet;
        $sourceName  = $transfer->source_display_name;
        $destName    = $transfer->destination_display_name;
        $user        = $request->user();
        $userId      = $user?->id;
        $businessId  = $user?->business_id ?? $transfer->business_id;

        if ($user && !$user->isPlatformAdmin() && !$user->isOwnerBisnis() && $user->outlet_id) {
            if ($transfer->destination_outlet_id && (int)$transfer->destination_outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda hanya berhak menerima transfer barang untuk cabang tujuan penempatan Anda.'
                ], 403);
            }
        }

        $receiveDate = date('Y-m-d');
        $disposition = $validated['return_disposition'] ?? 'RETURN_TO_SOURCE';
        $defaultReturnReason = $validated['return_reason'] ?? 'Barang rusak / kurang saat pengiriman';

        $totalReceived = 0;
        $totalReturned = 0;
        $totalOriginal = 0;

        DB::transaction(function () use (
            $transfer, $destOutlet, $sourceName, $destName, $userId, $businessId,
            $receiveDate, $validated, $disposition, $defaultReturnReason,
            &$totalReceived, &$totalReturned, &$totalOriginal
        ) {
            $itemsMap = !empty($validated['items']) ? collect($validated['items'])->keyBy('id') : null;

            foreach ($transfer->items as $item) {
                $originalInputQty = (float)($item->input_qty ?? $item->qty);
                $totalOriginal += $originalInputQty;

                if ($itemsMap && $itemsMap->has($item->id)) {
                    $itemInput = $itemsMap->get($item->id);
                    $receivedInputQty = (float)($itemInput['received_qty'] ?? $originalInputQty);
                    if ($receivedInputQty > $originalInputQty) {
                        throw new \InvalidArgumentException("Jumlah diterima untuk item {$item->item_name} ({$receivedInputQty}) tidak boleh melebihi jumlah kirim ({$originalInputQty}).");
                    }
                    $itemReason = !empty($itemInput['reason']) ? trim($itemInput['reason']) : $defaultReturnReason;
                } else {
                    $receivedInputQty = $originalInputQty;
                    $itemReason = $defaultReturnReason;
                }

                $returnedInputQty = max(0, $originalInputQty - $receivedInputQty);
                $totalReceived += $receivedInputQty;
                $totalReturned += $returnedInputQty;

                // Hitung rasio konversi jika satuan input (misal Kg) berbeda dari base satuan pakai (misal Gram)
                $ratio = ($originalInputQty > 0) ? ($item->qty / $originalInputQty) : 1;
                $receivedBaseQty = round($receivedInputQty * $ratio, 4);

                // Update data item transfer
                $item->update([
                    'received_qty'        => $receivedInputQty,
                    'returned_qty'        => $returnedInputQty,
                    'return_approved_qty' => 0,
                    'return_rejected_qty' => 0,
                    'return_reason'       => $returnedInputQty > 0 ? $itemReason : null,
                ]);

                // 1. TAMBAH STOK KE CABANG TUJUAN (Hanya sejumlah fisik yang benar-benar diterima)
                if ($receivedBaseQty > 0 && $destOutlet) {
                    if ($item->item_type === 'PRODUCT' && $item->menu_id) {
                        $menu = Menu::find($item->menu_id);
                        if ($menu && $menu->track_stock) {
                            try {
                                if (Schema::hasTable('outlet_menus')) {
                                    $destOm = OutletMenu::firstOrCreate(
                                        ['outlet_id' => $destOutlet->id, 'menu_id' => $menu->id],
                                        ['stock' => 0, 'min_stock' => $menu->min_stock]
                                    );
                                    $destOm->increment('stock', $receivedInputQty);

                                    if ($item->unit_price !== null && (float)$item->unit_price > 0) {
                                        $destOm->cost_price = (float)$item->unit_price;
                                        $destOm->save();
                                    } elseif ($transfer->source_outlet_id) {
                                        $sourceOm = OutletMenu::where('outlet_id', $transfer->source_outlet_id)->where('menu_id', $menu->id)->first();
                                        $sourceCost = $sourceOm?->cost_price ?? $menu->cost_price;
                                        if ($sourceCost !== null && (float)$sourceCost > 0) {
                                            $destOm->cost_price = (float)$sourceCost;
                                            $destOm->save();
                                        }
                                    }
                                }
                            } catch (\Throwable $e) {}
                            $menu->increment('stock', $receivedInputQty);
                        }
                    } elseif (in_array($item->item_type, ['INGREDIENT', 'PERLENGKAPAN']) && $item->ingredient_id) {
                        $ingredient = Ingredient::find($item->ingredient_id);
                        if ($ingredient) {
                            $inputUnit = $item->input_unit ?: $item->unit;
                            $canConvert = $inputUnit !== $item->unit && $receivedInputQty > 0;
                            $noteSuffix = $canConvert ? " ({$receivedInputQty} {$inputUnit} ≈ " . number_format($receivedBaseQty, 0, ',', '.') . " {$item->unit})" : "";
                            if ($returnedInputQty > 0) {
                                $noteSuffix .= " [Diterima: " . number_format($receivedInputQty, 0, ',', '.') . "/{$originalInputQty} {$inputUnit}]";
                            }

                            // Ambil harga modal dari item transfer (jika pembelian online / transfer ada harga khusus) atau dari cabang asal
                            if ($item->unit_price !== null && (float)$item->unit_price > 0) {
                                $sourcePricePerBeli = (float)$item->unit_price;
                            } elseif ($item->total_price !== null && (float)$item->total_price > 0 && $originalInputQty > 0) {
                                $sourcePricePerBeli = (float)$item->total_price / $originalInputQty;
                            } elseif ($transfer->source_outlet_id) {
                                $sourcePricePerBeli = $ingredient->hargaForOutlet($transfer->source_outlet_id);
                            } else {
                                $sourcePricePerBeli = (float)$ingredient->harga;
                            }

                            $sourcePricePerPakai = $sourcePricePerBeli / max((float)$ingredient->konversi, 1);
                            $itemTotalPrice = round(($receivedBaseQty / max((float)$ingredient->konversi, 1)) * $sourcePricePerBeli, 2);

                            // Cabang tujuan menyerap stok transfer ke moving average mandiri miliknya
                            $destAvg = $ingredient->recalculateMovingAverage($receivedBaseQty, $sourcePricePerPakai, $destOutlet->id);

                            $isExternalPurchase = !$transfer->source_outlet_id || $transfer->source_type === 'EXTERNAL';
                            $movementType = $isExternalPurchase ? 'PURCHASE' : 'TRANSFER_IN';
                            $movementNote = $isExternalPurchase
                                ? "Penerimaan belanja online dari {$sourceName}{$noteSuffix} ({$transfer->transfer_no})"
                                : "Transfer masuk dari {$sourceName}{$noteSuffix} ({$transfer->transfer_no})";

                            StockMovement::create([
                                'date'          => $receiveDate,
                                'ingredient_id' => $ingredient->id,
                                'type'          => $movementType,
                                'qty'           => $receivedBaseQty,
                                'unit_price'    => $sourcePricePerBeli,
                                'total_price'   => $itemTotalPrice,
                                'cost_before'   => $destAvg['cost_before'],
                                'cost_after'    => $destAvg['cost_after'],
                                'note'          => $movementNote,
                                'transfer_id'   => $transfer->id,
                                'outlet_id'     => $destOutlet->id,
                                'user_id'       => $userId,
                                'created_by'    => $userId,
                            ]);
                        }
                    }
                }

                // 2. PENANGANAN RETUR: STOK CABANG ASAL BELUM DITAMBAHKAN SAMPAI DISETUJUI (APPROVAL) OLEH CABANG ASAL
            }

            // Tentukan status transfer akhir
            $finalStatus = 'COMPLETED';
            $returnStatus = 'NONE';
            if ($totalReturned > 0) {
                $finalStatus = ($totalReceived <= 0 && $totalOriginal > 0) ? 'RETURNED' : 'PARTIALLY_RETURNED';
                $returnStatus = 'PENDING'; // Menunggu persetujuan cabang asal
            }

            $transfer->update([
                'status'             => $finalStatus,
                'received_at'        => now(),
                'received_by'        => $userId,
                'received_notes'     => $validated['received_notes'] ?? null,
                'return_status'      => $returnStatus,
                'returned_at'        => $totalReturned > 0 ? now() : null,
                'returned_by'        => $totalReturned > 0 ? $userId : null,
                'return_reason'      => $totalReturned > 0 ? $defaultReturnReason : null,
                'return_disposition' => $totalReturned > 0 ? $disposition : null,
                'updated_by'         => $userId,
            ]);
        });

        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'items.menu',
            'creator',
            'updater',
            'receiver',
            'returner',
            'returnApprover',
            'returnRejecter',
            'stockMovements'
        ]);

        $message = ($totalReturned > 0)
            ? "Penerimaan dicatat. Pengajuan retur {$totalReturned} unit telah dikirim dan MENUNGGU PERSETUJUAN (APPROVAL) dari cabang pengirim ({$sourceName})."
            : "Transfer {$transfer->transfer_no} berhasil diterima penuh! Stok cabang tujuan telah diperbarui.";

        return response()->json([
            'message'  => $message,
            'transfer' => $transfer,
        ]);
    }

    public function cancel(Request $request, Transfer $transfer)
    {
        $user = $request->user();
        if ($user && !$user->isPlatformAdmin() && !$user->isOwnerBisnis() && $user->outlet_id) {
            if ((int)$transfer->source_outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Hanya cabang pengirim atau pemilik bisnis yang dapat membatalkan transfer ini.'
                ], 403);
            }
        }

        if ($transfer->status === 'CANCELLED') {
            return response()->json(['message' => 'Transfer ini sudah dibatalkan sebelumnya.'], 422);
        }

        $wasCompleted = in_array($transfer->status, ['COMPLETED', 'PARTIALLY_RETURNED', 'RETURNED']);

        DB::transaction(function () use ($request, $transfer, $wasCompleted) {
            // 1. Hapus atau batalkan mutasi bahan baku yang tercatat untuk transfer ini
            StockMovement::where('transfer_id', $transfer->id)->delete();

            // 2. Kembalikan saldo mutasi produk retail (PRODUCT)
            foreach ($transfer->items as $item) {
                if ($item->item_type === 'PRODUCT' && $item->menu_id) {
                    $menu = Menu::find($item->menu_id);
                    if ($menu && $menu->track_stock) {
                        $qty = (float)$item->qty;

                        // Kembalikan stok ke outlet asal (karena saat pengiriman stok asal selalu dipotong)
                        if ($transfer->source_outlet_id) {
                            try {
                                if (Schema::hasTable('outlet_menus')) {
                                    $sourceOm = OutletMenu::where('outlet_id', $transfer->source_outlet_id)
                                        ->where('menu_id', $menu->id)->first();
                                    if ($sourceOm) {
                                        $sourceOm->increment('stock', $qty);
                                    }
                                }
                            } catch (\Throwable $e) {}
                            $menu->increment('stock', $qty);
                        }

                        // Tarik kembali stok dari outlet tujuan HANYA jika transfer sempat berstatus COMPLETED
                        if ($wasCompleted && $transfer->destination_outlet_id) {
                            $receivedQ = (float)($item->received_qty ?? ($transfer->status === 'COMPLETED' ? $qty : 0));
                            if ($receivedQ > 0) {
                                try {
                                    if (Schema::hasTable('outlet_menus')) {
                                        $destOm = OutletMenu::where('outlet_id', $transfer->destination_outlet_id)
                                            ->where('menu_id', $menu->id)->first();
                                        if ($destOm) {
                                            $destOm->decrement('stock', $receivedQ);
                                        }
                                    }
                                } catch (\Throwable $e) {}
                                $menu->decrement('stock', $receivedQ);
                            }
                        }
                    }
                }
            }

            $transfer->update([
                'status'        => 'CANCELLED',
                'return_status' => 'NONE',
                'updated_by'    => $request->user()?->id,
            ]);
        });

        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'items.menu',
            'creator',
            'updater',
            'receiver',
            'returner',
            'returnApprover',
            'returnRejecter',
            'stockMovements'
        ]);

        return response()->json([
            'message'  => 'Transfer berhasil dibatalkan dan saldo stok telah dikembalikan.',
            'transfer' => $transfer,
        ]);
    }

    /**
     * Fitur Retur Transfer Barang (Diajukan oleh Cabang Tujuan -> Menunggu Approval Cabang Asal)
     */
    public function returnTransfer(Request $request, Transfer $transfer)
    {
        if ($transfer->status === 'COMPLETED' || $transfer->status === 'PARTIALLY_RETURNED' || $transfer->status === 'RETURNED') {
            return response()->json(['message' => 'Transfer ini sudah diproses penerimaan/returnya.'], 422);
        }
        if ($transfer->status === 'CANCELLED') {
            return response()->json(['message' => 'Transfer yang sudah dibatalkan tidak dapat diretur.'], 422);
        }

        $user = $request->user();
        if ($user && !$user->isPlatformAdmin() && !$user->isOwnerBisnis() && $user->outlet_id) {
            if ($transfer->destination_outlet_id && (int)$transfer->destination_outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Anda hanya berhak mengajukan retur untuk transfer yang ditujukan ke cabang Anda.'
                ], 403);
            }
        }

        $validated = $request->validate([
            'return_disposition'   => 'required|string|in:RECORD_AS_WASTE,RETURN_TO_SOURCE',
            'return_reason'        => 'required|string|max:255',
            'return_notes'         => 'nullable|string|max:500',
            'items'                => 'required|array|min:1',
            'items.*.id'           => 'required|exists:transfer_items,id',
            'items.*.returned_qty' => 'required|numeric|min:0',
            'items.*.reason'       => 'nullable|string|max:255',
        ]);

        $userId = $request->user()?->id;
        $disposition = $validated['return_disposition'];
        $returnReason = $validated['return_reason'];

        $totalReturned = 0;
        $totalOriginal = 0;

        DB::transaction(function () use ($transfer, $validated, $userId, $disposition, $returnReason, &$totalReturned, &$totalOriginal) {
            $itemsMap = collect($validated['items'])->keyBy('id');

            foreach ($transfer->items as $item) {
                $itemInput = $itemsMap->get($item->id);
                if (!$itemInput) continue;

                $returnedQty = (float)($itemInput['returned_qty'] ?? 0);
                $originalQty = (float)($item->input_qty ?? $item->qty);
                $totalOriginal += $originalQty;
                $totalReturned += $returnedQty;

                if ($returnedQty > $originalQty) {
                    throw new \InvalidArgumentException("Jumlah retur untuk item {$item->item_name} ({$returnedQty}) tidak boleh melebihi jumlah kirim ({$originalQty}).");
                }

                $itemReason = !empty($itemInput['reason']) ? trim($itemInput['reason']) : $returnReason;
                $receivedQty = max(0, $originalQty - $returnedQty);

                // Update item transfer record
                $item->update([
                    'returned_qty'        => $returnedQty,
                    'received_qty'        => $receivedQty,
                    'return_approved_qty' => 0,
                    'return_rejected_qty' => 0,
                    'return_reason'       => $itemReason,
                ]);

                // Note: Stok cabang asal belum dipulihkan sampai cabang asal menyetujui (approve) retur.
            }

            // Tentukan status transfer akhir
            $newStatus = ($totalReturned >= $totalOriginal && $totalOriginal > 0) ? 'RETURNED' : 'PARTIALLY_RETURNED';

            $transfer->update([
                'status'             => $newStatus,
                'return_status'      => 'PENDING', // Menunggu persetujuan cabang asal
                'returned_at'        => now(),
                'returned_by'        => $userId,
                'return_reason'      => $returnReason,
                'return_disposition' => $disposition,
                'return_notes'       => $validated['return_notes'] ?? null,
                'updated_by'         => $userId,
            ]);
        });

        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'items.menu',
            'creator',
            'updater',
            'receiver',
            'returner',
            'returnApprover',
            'returnRejecter',
            'stockMovements'
        ]);

        $sourceName = $transfer->source_display_name;
        $statusText = $transfer->status === 'RETURNED' ? 'Retur Total (Seluruh Item)' : 'Retur Parsial (Sebagian Item)';

        return response()->json([
            'message'  => "Pengajuan retur transfer {$transfer->transfer_no} berhasil dicatat ({$statusText}). Menunggu persetujuan (APPROVAL) dari cabang pengirim ({$sourceName}).",
            'transfer' => $transfer,
        ]);
    }

    /**
     * Fitur Approve Retur oleh Cabang Pengirim (Cabang Asal) atau Owner Bisnis
     */
    public function approveReturn(Request $request, Transfer $transfer)
    {
        $user = $request->user();
        $userId = $user?->id;
        $businessId = $user?->business_id ?? $transfer->business_id;

        // Check permission: Hanya cabang asal (pengirim), owner bisnis, atau platform admin yang berhak approve
        if ($user && !$user->isPlatformAdmin() && !$user->isOwnerBisnis() && $user->outlet_id) {
            if ($transfer->source_outlet_id && (int)$transfer->source_outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Hanya cabang pengirim (' . ($transfer->source_display_name) . ') atau pemilik bisnis yang berhak menyetujui retur transfer ini.'
                ], 403);
            }
        }

        if ($transfer->return_status !== 'PENDING' && !in_array($transfer->status, ['RETURNED', 'PARTIALLY_RETURNED'])) {
            return response()->json(['message' => 'Transfer ini tidak memiliki pengajuan retur yang menunggu persetujuan.'], 422);
        }

        $validated = $request->validate([
            'return_disposition'    => 'nullable|string|in:RETURN_TO_SOURCE,RECORD_AS_WASTE',
            'return_approval_notes' => 'nullable|string|max:500',
            'items'                 => 'nullable|array',
            'items.*.id'            => 'required_with:items|exists:transfer_items,id',
            'items.*.approved_qty'  => 'nullable|numeric|min:0',
            'items.*.rejected_qty'  => 'nullable|numeric|min:0',
            'items.*.notes'         => 'nullable|string|max:255',
        ]);

        $disposition = $validated['return_disposition'] ?? ($transfer->return_disposition ?: 'RETURN_TO_SOURCE');
        $approvalNotes = $validated['return_approval_notes'] ?? null;
        $returnDate = date('Y-m-d');

        DB::transaction(function () use ($transfer, $validated, $disposition, $approvalNotes, $returnDate, $userId, $businessId) {
            $itemsMap = !empty($validated['items']) ? collect($validated['items'])->keyBy('id') : null;

            $anyApproved = false;
            $anyRejected = false;

            foreach ($transfer->items as $item) {
                $returnedQty = (float)($item->returned_qty ?? 0);
                if ($returnedQty <= 0) {
                    continue;
                }

                if ($itemsMap && $itemsMap->has($item->id)) {
                    $itemInput = $itemsMap->get($item->id);
                    $approvedQty = isset($itemInput['approved_qty']) ? (float)$itemInput['approved_qty'] : $returnedQty;
                    $rejectedQty = isset($itemInput['rejected_qty']) ? (float)$itemInput['rejected_qty'] : max(0, $returnedQty - $approvedQty);
                } else {
                    $approvedQty = $returnedQty;
                    $rejectedQty = 0;
                }

                if ($approvedQty > $returnedQty) {
                    $approvedQty = $returnedQty;
                    $rejectedQty = 0;
                }

                if ($approvedQty > 0) $anyApproved = true;
                if ($rejectedQty > 0) $anyRejected = true;

                $item->update([
                    'return_approved_qty' => $approvedQty,
                    'return_rejected_qty' => $rejectedQty,
                ]);

                if ($approvedQty <= 0) {
                    continue;
                }

                // Hitung rasio konversi unit
                $originalInputQty = (float)($item->input_qty ?? $item->qty);
                $ratio = ($originalInputQty > 0) ? ($item->qty / $originalInputQty) : 1;
                $approvedBaseQty = round($approvedQty * $ratio, 4);

                if ($disposition === 'RETURN_TO_SOURCE') {
                    // Pulihkan stok ke outlet asal
                    if ($item->item_type === 'PRODUCT' && $item->menu_id) {
                        $menu = Menu::find($item->menu_id);
                        if ($menu && $menu->track_stock && $transfer->source_outlet_id) {
                            try {
                                if (Schema::hasTable('outlet_menus')) {
                                    $sourceOm = OutletMenu::firstOrCreate(
                                        ['outlet_id' => $transfer->source_outlet_id, 'menu_id' => $menu->id],
                                        ['stock' => 0, 'min_stock' => $menu->min_stock]
                                    );
                                    $sourceOm->increment('stock', $approvedQty);
                                }
                            } catch (\Throwable $e) {}
                            $menu->increment('stock', $approvedQty);
                        }
                    } elseif (in_array($item->item_type, ['INGREDIENT', 'PERLENGKAPAN']) && $item->ingredient_id) {
                        $ing = Ingredient::find($item->ingredient_id);
                        if ($ing && $transfer->source_outlet_id) {
                            $sourcePricePerBeli = $ing->hargaForOutlet($transfer->source_outlet_id);
                            $sourcePricePerPakai = $sourcePricePerBeli / max((float)$ing->konversi, 1);
                            $itemTotalPrice = round(($approvedBaseQty / max((float)$ing->konversi, 1)) * $sourcePricePerBeli, 2);

                            // Recalculate moving average at source outlet
                            $sourceAvg = $ing->recalculateMovingAverage($approvedBaseQty, $sourcePricePerPakai, $transfer->source_outlet_id);

                            StockMovement::create([
                                'date'          => $returnDate,
                                'ingredient_id' => $ing->id,
                                'type'          => 'TRANSFER_IN',
                                'qty'           => $approvedBaseQty,
                                'unit_price'    => $sourcePricePerBeli,
                                'total_price'   => $itemTotalPrice,
                                'cost_before'   => $sourceAvg['cost_before'],
                                'cost_after'    => $sourceAvg['cost_after'],
                                'note'          => "Retur transfer masuk (Disetujui) dari {$transfer->destination_display_name} ({$transfer->transfer_no})" . ($approvalNotes ? " - Catatan: {$approvalNotes}" : ""),
                                'transfer_id'   => $transfer->id,
                                'outlet_id'     => $transfer->source_outlet_id,
                                'user_id'       => $userId,
                                'created_by'    => $userId,
                            ]);
                        }
                    }
                } elseif ($disposition === 'RECORD_AS_WASTE') {
                    // Catat sebagai Waste Log di cabang asal / pengiriman
                    $costPerUnit = 0;
                    $ingredientId = null;
                    $menuId = null;

                    if (in_array($item->item_type, ['INGREDIENT', 'PERLENGKAPAN']) && $item->ingredient_id) {
                        $ing = Ingredient::find($item->ingredient_id);
                        $ingredientId = $ing?->id;
                        $costPerUnit = (float)($ing?->cost_per_unit ?? $ing?->harga ?? 0);
                    } elseif ($item->item_type === 'PRODUCT' && $item->menu_id) {
                        $menu = Menu::find($item->menu_id);
                        $menuId = $menu?->id;
                        $costPerUnit = (float)($menu?->hpp ?? $menu?->cogs ?? $menu?->price ?? 0);
                    }

                    $lossCost = round($approvedBaseQty * $costPerUnit, 2);
                    $wasteNo = 'WST-RET-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

                    if (Schema::hasTable('waste_logs')) {
                        WasteLog::create([
                            'business_id'     => $businessId,
                            'waste_no'        => $wasteNo,
                            'date'            => $returnDate,
                            'ingredient_id'   => $ingredientId,
                            'menu_id'         => $menuId,
                            'outlet_id'       => $transfer->source_outlet_id ?: $transfer->destination_outlet_id,
                            'qty'             => $approvedQty,
                            'unit_type'       => $item->input_unit ?: $item->unit ?: 'PAKAI',
                            'qty_pakai'       => $approvedBaseQty,
                            'cost_per_unit'   => $costPerUnit,
                            'loss_cost'       => $lossCost,
                            'reason_category' => 'DELIVERY_DAMAGE',
                            'notes'           => "Retur Transfer {$transfer->transfer_no} disetujui sebagai Waste: " . ($item->return_reason ?: $transfer->return_reason) . ($approvalNotes ? " ({$approvalNotes})" : ""),
                            'action_taken'    => 'Disetujui sebagai Kerugian Pengiriman (Waste)',
                            'user_id'         => $userId,
                            'created_by'      => $userId,
                            'updated_by'      => $userId,
                        ]);
                    }
                }
            }

            $finalReturnStatus = ($anyApproved && $anyRejected) ? 'PARTIALLY_APPROVED' : ($anyApproved ? 'APPROVED' : 'REJECTED');

            $transfer->update([
                'return_status'         => $finalReturnStatus,
                'return_disposition'    => $disposition,
                'return_approved_at'    => now(),
                'return_approved_by'    => $userId,
                'return_approval_notes' => $approvalNotes,
                'updated_by'            => $userId,
            ]);
        });

        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'items.menu',
            'creator',
            'updater',
            'receiver',
            'returner',
            'returnApprover',
            'returnRejecter',
            'stockMovements'
        ]);

        $dispText = $disposition === 'RETURN_TO_SOURCE' ? 'Stok telah dikembalikan ke cabang asal' : 'Dicatat sebagai kerugian pengiriman (Waste)';

        return response()->json([
            'message'  => "Persetujuan retur untuk transfer {$transfer->transfer_no} berhasil disimpan ({$dispText}).",
            'transfer' => $transfer,
        ]);
    }

    /**
     * Fitur Reject / Tolak Retur oleh Cabang Pengirim (Cabang Asal) atau Owner Bisnis
     */
    public function rejectReturn(Request $request, Transfer $transfer)
    {
        $user = $request->user();
        $userId = $user?->id;

        // Check permission: Hanya cabang asal (pengirim), owner bisnis, atau platform admin yang berhak reject
        if ($user && !$user->isPlatformAdmin() && !$user->isOwnerBisnis() && $user->outlet_id) {
            if ($transfer->source_outlet_id && (int)$transfer->source_outlet_id !== (int)$user->outlet_id) {
                return response()->json([
                    'message' => 'Hanya cabang pengirim (' . ($transfer->source_display_name) . ') atau pemilik bisnis yang berhak menolak retur transfer ini.'
                ], 403);
            }
        }

        if ($transfer->return_status !== 'PENDING' && !in_array($transfer->status, ['RETURNED', 'PARTIALLY_RETURNED'])) {
            return response()->json(['message' => 'Transfer ini tidak memiliki pengajuan retur yang menunggu persetujuan.'], 422);
        }

        $validated = $request->validate([
            'return_rejected_reason' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($transfer, $validated, $userId) {
            foreach ($transfer->items as $item) {
                $returnedQty = (float)($item->returned_qty ?? 0);
                if ($returnedQty > 0) {
                    $item->update([
                        'return_approved_qty' => 0,
                        'return_rejected_qty' => $returnedQty,
                    ]);
                }
            }

            $transfer->update([
                'return_status'          => 'REJECTED',
                'return_rejected_at'     => now(),
                'return_rejected_by'     => $userId,
                'return_rejected_reason' => $validated['return_rejected_reason'],
                'updated_by'             => $userId,
            ]);
        });

        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'items.menu',
            'creator',
            'updater',
            'receiver',
            'returner',
            'returnApprover',
            'returnRejecter',
            'stockMovements'
        ]);

        return response()->json([
            'message'  => "Retur transfer {$transfer->transfer_no} telah ditolak. Alasan penolakan telah dicatat.",
            'transfer' => $transfer,
        ]);
    }

    /**
     * Fitur Hapus / Rollback Transfer Stok (Khusus Data Terakhir & Otoritas Owner Bisnis)
     * Mengembalikan saldo stok, memulihkan status barang, dan menghitung ulang Moving Average secara real-time.
     */
    public function destroy(Request $request, Transfer $transfer)
    {
        $user = $request->user();
        if (!$user || (!$user->isOwnerBisnis() && !$user->isPlatformAdmin())) {
            return response()->json([
                'message' => 'Hanya Owner Bisnis atau Admin Platform yang berwenang menghapus transaksi transfer stok.'
            ], 403);
        }

        // Cek apakah ada transfer berikutnya yang lebih baru pada cabang yang terlibat
        $hasNewerTransfer = Transfer::where('business_id', $transfer->business_id)
            ->where('id', '!=', $transfer->id)
            ->where(function ($q) use ($transfer) {
                if ($transfer->source_outlet_id) {
                    $q->where('source_outlet_id', $transfer->source_outlet_id)
                      ->orWhere('destination_outlet_id', $transfer->source_outlet_id);
                }
                if ($transfer->destination_outlet_id) {
                    $q->orWhere('source_outlet_id', $transfer->destination_outlet_id)
                      ->orWhere('destination_outlet_id', $transfer->destination_outlet_id);
                }
            })
            ->where(function ($q) use ($transfer) {
                $q->where('date', '>', $transfer->date)
                  ->orWhere(function ($sub) use ($transfer) {
                      $sub->where('date', $transfer->date)->where('id', '>', $transfer->id);
                  });
            })
            ->exists();

        if ($hasNewerTransfer) {
            return response()->json([
                'message' => 'Hanya transaksi transfer terakhir pada cabang ini yang dapat dihapus agar saldo stok dan kalkulasi HPP Moving Average tetap runut dan konsisten.'
            ], 422);
        }

        DB::transaction(function () use ($transfer) {
            // 1. Rollback & Hapus Hutang Supplier (jika ada)
            if ($transfer->payable_id) {
                PayablePayment::where('payable_id', $transfer->payable_id)->delete();
                Payable::where('id', $transfer->payable_id)->delete();
            }

            // 2. Kumpulkan bahan dan outlet yang terdampak sebelum penghapusan
            $affectedIngredients = [];

            if ($transfer->source_outlet_id) {
                foreach ($transfer->items as $item) {
                    if ($item->ingredient_id) {
                        $affectedIngredients[$item->ingredient_id][$transfer->source_outlet_id] = true;
                    } elseif ($item->item_type === 'PRODUCT' && $item->menu_id) {
                        $menu = Menu::find($item->menu_id);
                        if ($menu && $menu->track_stock) {
                            $inputQty = (float)($item->input_qty ?? $item->qty);
                            if (Schema::hasTable('outlet_menus')) {
                                $sourceOm = OutletMenu::where('outlet_id', $transfer->source_outlet_id)->where('menu_id', $menu->id)->first();
                                $sourceOm?->increment('stock', $inputQty);
                            }
                            $menu->increment('stock', $inputQty);
                        }
                    }
                }
            }

            if ($transfer->destination_outlet_id && in_array($transfer->status, ['COMPLETED', 'PARTIALLY_RETURNED', 'RETURNED'])) {
                foreach ($transfer->items as $item) {
                    if ($item->ingredient_id) {
                        $affectedIngredients[$item->ingredient_id][$transfer->destination_outlet_id] = true;
                    } elseif ($item->item_type === 'PRODUCT' && $item->menu_id) {
                        $menu = Menu::find($item->menu_id);
                        if ($menu && $menu->track_stock) {
                            $receivedQty = (float)($item->received_qty ?? ($item->input_qty ?? $item->qty));
                            if (Schema::hasTable('outlet_menus')) {
                                $destOm = OutletMenu::where('outlet_id', $transfer->destination_outlet_id)->where('menu_id', $menu->id)->first();
                                $destOm?->decrement('stock', $receivedQty);
                            }
                            $menu->decrement('stock', $receivedQty);
                        }
                    }
                }
            }

            // 3. Hapus seluruh mutasi stok terkait transfer ini
            StockMovement::where('transfer_id', $transfer->id)->delete();

            // 4. Hapus Waste Log jika ada retur transfer yang dicatat sebagai waste
            if (Schema::hasTable('waste_logs')) {
                WasteLog::where('notes', 'like', "%{$transfer->transfer_no}%")->delete();
            }

            // 5. Hapus Transfer Items & Record Transfer
            $transfer->items()->delete();
            $transfer->delete();

            // 6. Hitung ulang Moving Average & Saldo Stok untuk semua bahan dan cabang terdampak
            foreach ($affectedIngredients as $ingredientId => $outlets) {
                $ing = Ingredient::find($ingredientId);
                if ($ing) {
                    foreach (array_keys($outlets) as $outId) {
                        $ing->recomputeMovingAverageFromHistory((int)$outId);
                    }
                }
            }
        });

        return response()->json([
            'message' => "Transfer {$transfer->transfer_no} berhasil dihapus. Stok telah dikembalikan dan seluruh HPP Moving Average telah dihitung ulang secara real-time.",
        ]);
    }
}
