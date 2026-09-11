<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\StockMovement;
use App\Models\Outlet;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\OutletMenu;
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
            'updater'
        ])
        ->orderByDesc('date')
        ->orderByDesc('id');

        if ($request->from) {
            $query->where('date', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('date', '<=', $request->to);
        }
        if ($request->source_outlet_id) {
            $query->where('source_outlet_id', $request->source_outlet_id);
        }
        if ($request->destination_outlet_id) {
            $query->where('destination_outlet_id', $request->destination_outlet_id);
        }
        if ($request->status) {
            $query->where('status', $request->status);
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
            'notes'                 => 'nullable|string|max:500',
            'driver_name'           => 'nullable|string|max:100',
            'vehicle_no'            => 'nullable|string|max:50',
            'items'                 => 'required|array|min:1',
            'items.*.item_type'     => 'nullable|string|in:INGREDIENT,PRODUCT',
            'items.*.ingredient_id' => 'nullable|exists:ingredients,id',
            'items.*.menu_id'       => 'nullable|exists:menus,id',
            'items.*.qty'           => 'nullable|numeric|min:0.0001',
            'items.*.unit'          => 'nullable|string|max:30',
            'items.*.input_qty'     => 'nullable|numeric|min:0.0001',
            'items.*.input_unit'    => 'nullable|string|max:30',
            'items.*.notes'         => 'nullable|string|max:255',
        ], [
            'items.min' => 'Pilih minimal satu barang atau bahan untuk ditransfer.',
        ]);

        // Verifikasi lokasi asal dan tujuan
        $hasSource = !empty($validated['source_outlet_id']) || !empty(trim($validated['source_name'] ?? ''));
        $hasDest = !empty($validated['destination_outlet_id']) || !empty(trim($validated['destination_name'] ?? ''));

        if (!$hasSource) {
            return response()->json(['message' => 'Tentukan lokasi asal (pilih outlet terdaftar atau ketik nama lokasi asal).'], 422);
        }
        if (!$hasDest) {
            return response()->json(['message' => 'Tentukan lokasi tujuan (pilih outlet terdaftar atau ketik nama lokasi tujuan).'], 422);
        }

        $sourceOutletId = !empty($validated['source_outlet_id']) ? (int)$validated['source_outlet_id'] : null;
        $destOutletId   = !empty($validated['destination_outlet_id']) ? (int)$validated['destination_outlet_id'] : null;

        if ($sourceOutletId && $destOutletId && $sourceOutletId === $destOutletId) {
            return response()->json(['message' => 'Outlet tujuan tidak boleh sama dengan outlet pengirim.'], 422);
        }

        $transfer = DB::transaction(function () use ($request, $validated, $sourceOutletId, $destOutletId) {
            $date = $validated['date'];
            $dateClean = str_replace('-', '', $date);
            $countToday = Transfer::whereDate('date', $date)->count() + 1;
            $transferNo = 'TRF-' . $dateClean . '-' . str_pad($countToday, 4, '0', STR_PAD_LEFT);

            $sourceOutlet = $sourceOutletId ? Outlet::find($sourceOutletId) : null;
            $destOutlet   = $destOutletId ? Outlet::find($destOutletId) : null;

            $sourceName = $sourceOutlet ? $sourceOutlet->name : trim($validated['source_name'] ?? 'Gudang Asal');
            $destName   = $destOutlet ? $destOutlet->name : trim($validated['destination_name'] ?? 'Gudang Tujuan');

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

            $transfer = Transfer::create([
                'transfer_no'           => $transferNo,
                'date'                  => $date,
                'source_type'           => $sourceOutlet ? 'OUTLET' : ($validated['source_type'] ?? 'EXTERNAL'),
                'source_name'           => $sourceName,
                'source_outlet_id'      => $sourceOutlet?->id,
                'destination_type'      => $destOutlet ? 'OUTLET' : ($validated['destination_type'] ?? 'EXTERNAL'),
                'destination_name'      => $destName,
                'destination_outlet_id' => $destOutlet?->id,
                'transfer_type'         => $transferType,
                'status'                => 'COMPLETED',
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

                    TransferItem::create([
                        'transfer_id' => $transfer->id,
                        'item_type'   => 'PRODUCT',
                        'menu_id'     => $menu->id,
                        'qty'         => $qty,
                        'unit'        => $unit,
                        'input_qty'   => $qty,
                        'input_unit'  => $unit,
                        'notes'       => $itemData['notes'] ?? null,
                    ]);

                    // Mutasi stok retail outlet asal (jika outlet terdaftar)
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

                    // Mutasi stok retail outlet tujuan (jika outlet terdaftar)
                    if ($destOutlet && $menu->track_stock) {
                        try {
                            if (Schema::hasTable('outlet_menus')) {
                                $destOm = OutletMenu::firstOrCreate(
                                    ['outlet_id' => $destOutlet->id, 'menu_id' => $menu->id],
                                    ['stock' => 0, 'min_stock' => $menu->min_stock]
                                );
                                $destOm->increment('stock', $qty);
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

                    TransferItem::create([
                        'transfer_id'   => $transfer->id,
                        'item_type'     => 'INGREDIENT',
                        'ingredient_id' => $ingredient->id,
                        'qty'           => $baseQty,
                        'unit'          => $baseUnit,
                        'input_qty'     => $inputQty,
                        'input_unit'    => $inputUnit,
                        'notes'         => $itemData['notes'] ?? null,
                    ]);

                    $noteSuffix = $canConvert ? " ({$inputQty} {$inputUnit} ≈ " . number_format($baseQty, 0, ',', '.') . " {$baseUnit})" : "";

                    // 1. Mutasi TRANSFER_OUT dari outlet asal (jika outlet terdaftar)
                    if ($sourceOutlet) {
                        StockMovement::create([
                            'date'          => $date,
                            'ingredient_id' => $ingredient->id,
                            'type'          => 'TRANSFER_OUT',
                            'qty'           => $baseQty,
                            'note'          => "Transfer keluar ke {$destName}{$noteSuffix} ({$transferNo})",
                            'transfer_id'   => $transfer->id,
                            'outlet_id'     => $sourceOutlet->id,
                            'user_id'       => $userId,
                            'created_by'    => $userId,
                        ]);
                    }

                    // 2. Mutasi TRANSFER_IN ke outlet tujuan (jika outlet terdaftar)
                    if ($destOutlet) {
                        StockMovement::create([
                            'date'          => $date,
                            'ingredient_id' => $ingredient->id,
                            'type'          => 'TRANSFER_IN',
                            'qty'           => $baseQty,
                            'note'          => "Transfer masuk dari {$sourceName}{$noteSuffix} ({$transferNo})",
                            'transfer_id'   => $transfer->id,
                            'outlet_id'     => $destOutlet->id,
                            'user_id'       => $userId,
                            'created_by'    => $userId,
                        ]);
                    }
                }
            }

            return $transfer;
        });

        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'items.menu',
            'creator',
            'updater',
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
            'stockMovements'
        ]);
        return response()->json($transfer);
    }

    public function cancel(Request $request, Transfer $transfer)
    {
        if ($transfer->status === 'CANCELLED') {
            return response()->json(['message' => 'Transfer ini sudah dibatalkan sebelumnya.'], 422);
        }

        DB::transaction(function () use ($request, $transfer) {
            // 1. Hapus atau batalkan mutasi bahan baku
            StockMovement::where('transfer_id', $transfer->id)->delete();

            // 2. Kembalikan saldo mutasi produk retail (PRODUCT)
            foreach ($transfer->items as $item) {
                if ($item->item_type === 'PRODUCT' && $item->menu_id) {
                    $menu = Menu::find($item->menu_id);
                    if ($menu && $menu->track_stock) {
                        $qty = (float)$item->qty;

                        // Kembalikan ke outlet asal
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

                        // Tarik kembali dari outlet tujuan
                        if ($transfer->destination_outlet_id) {
                            try {
                                if (Schema::hasTable('outlet_menus')) {
                                    $destOm = OutletMenu::where('outlet_id', $transfer->destination_outlet_id)
                                        ->where('menu_id', $menu->id)->first();
                                    if ($destOm) {
                                        $destOm->decrement('stock', $qty);
                                    }
                                }
                            } catch (\Throwable $e) {}
                            $menu->decrement('stock', $qty);
                        }
                    }
                }
            }

            $transfer->update([
                'status'     => 'CANCELLED',
                'updated_by' => $request->user()?->id,
            ]);
        });

        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'items.menu',
            'creator',
            'updater'
        ]);

        return response()->json([
            'message'  => 'Transfer berhasil dibatalkan dan saldo stok telah dikembalikan.',
            'transfer' => $transfer,
        ]);
    }
}
