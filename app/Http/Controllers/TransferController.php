<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\StockMovement;
use App\Models\Outlet;
use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    public function index(Request $request)
    {
        $query = Transfer::with([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
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
            'source_outlet_id'      => 'required|exists:outlets,id',
            'destination_outlet_id' => 'required|exists:outlets,id|different:source_outlet_id',
            'notes'                 => 'nullable|string|max:500',
            'driver_name'           => 'nullable|string|max:100',
            'vehicle_no'            => 'nullable|string|max:50',
            'items'                 => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|exists:ingredients,id',
            'items.*.qty'           => 'nullable|numeric|min:0.0001',
            'items.*.unit'          => 'nullable|string|max:30',
            'items.*.input_qty'     => 'nullable|numeric|min:0.0001',
            'items.*.input_unit'    => 'nullable|string|max:30',
            'items.*.notes'         => 'nullable|string|max:255',
        ], [
            'destination_outlet_id.different' => 'Outlet tujuan harus berbeda dengan outlet pengirim.',
            'items.min' => 'Pilih minimal satu bahan baku untuk ditransfer.',
        ]);

        $transfer = DB::transaction(function () use ($request, $validated) {
            $date = $validated['date'];
            $dateClean = str_replace('-', '', $date);
            $countToday = Transfer::whereDate('date', $date)->count() + 1;
            $transferNo = 'TRF-' . $dateClean . '-' . str_pad($countToday, 4, '0', STR_PAD_LEFT);

            $sourceOutlet = Outlet::findOrFail($validated['source_outlet_id']);
            $destOutlet = Outlet::findOrFail($validated['destination_outlet_id']);

            $userId = $request->user()?->id;

            $transfer = Transfer::create([
                'transfer_no'           => $transferNo,
                'date'                  => $date,
                'source_outlet_id'      => $sourceOutlet->id,
                'destination_outlet_id' => $destOutlet->id,
                'status'                => 'COMPLETED',
                'notes'                 => $validated['notes'] ?? null,
                'driver_name'           => $validated['driver_name'] ?? null,
                'vehicle_no'            => $validated['vehicle_no'] ?? null,
                'total_items'           => count($validated['items']),
                'created_by'            => $userId,
                'updated_by'            => $userId,
            ]);

            foreach ($validated['items'] as $itemData) {
                $ingredient = Ingredient::findOrFail($itemData['ingredient_id']);

                $inputQty = isset($itemData['input_qty']) && $itemData['input_qty'] !== ''
                    ? (float) $itemData['input_qty']
                    : (float) ($itemData['qty'] ?? 0);

                $inputUnit = !empty($itemData['input_unit'])
                    ? trim($itemData['input_unit'])
                    : (!empty($itemData['unit']) ? trim($itemData['unit']) : (string)$ingredient->unit_pakai);

                // Check conversion: if unit is unit_beli and konversi > 1 and unit_beli != unit_pakai
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
                    'ingredient_id' => $ingredient->id,
                    'qty'           => $baseQty,
                    'unit'          => $baseUnit,
                    'input_qty'     => $inputQty,
                    'input_unit'    => $inputUnit,
                    'notes'         => $itemData['notes'] ?? null,
                ]);

                $noteSuffix = $canConvert ? " ({$inputQty} {$inputUnit} ≈ " . number_format($baseQty, 0, ',', '.') . " {$baseUnit})" : "";

                // 1. Catat mutasi TRANSFER_OUT dari outlet asal
                StockMovement::create([
                    'date'          => $date,
                    'ingredient_id' => $ingredient->id,
                    'type'          => 'TRANSFER_OUT',
                    'qty'           => $baseQty,
                    'note'          => "Transfer keluar ke {$destOutlet->name}{$noteSuffix} ({$transferNo})",
                    'transfer_id'   => $transfer->id,
                    'outlet_id'     => $sourceOutlet->id,
                    'user_id'       => $userId,
                    'created_by'    => $userId,
                ]);

                // 2. Catat mutasi TRANSFER_IN ke outlet tujuan
                StockMovement::create([
                    'date'          => $date,
                    'ingredient_id' => $ingredient->id,
                    'type'          => 'TRANSFER_IN',
                    'qty'           => $baseQty,
                    'note'          => "Transfer masuk dari {$sourceOutlet->name}{$noteSuffix} ({$transferNo})",
                    'transfer_id'   => $transfer->id,
                    'outlet_id'     => $destOutlet->id,
                    'user_id'       => $userId,
                    'created_by'    => $userId,
                ]);
            }

            return $transfer;
        });

        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
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
            // Hapus atau batalkan mutasi stok yang terhubung
            StockMovement::where('transfer_id', $transfer->id)->delete();

            $transfer->update([
                'status'     => 'CANCELLED',
                'updated_by' => $request->user()?->id,
            ]);
        });

        $transfer->load([
            'sourceOutlet',
            'destinationOutlet',
            'items.ingredient',
            'creator',
            'updater'
        ]);

        return response()->json([
            'message'  => 'Transfer berhasil dibatalkan dan mutasi stok telah dikembalikan.',
            'transfer' => $transfer,
        ]);
    }
}
