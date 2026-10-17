<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\OutletMenu;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\UrgentNote;

class StockDeductionService
{
    /**
     * Process ingredient stock deduction with Urgent Note deficit support.
     *
     * @param int $businessId
     * @param int $outletId
     * @param Transaction $trx
     * @param string $orderNumber
     * @param string $date
     * @param Ingredient $ing
     * @param float $requiredQty
     * @param string $itemName
     * @param string $itemType
     * @param int|null $menuId
     * @param bool $isShiftActive
     * @param bool $isPaid
     * @param int $userId
     * @param string|null $unit
     * @return array{is_deficit: bool, deducted_qty: float, pending_qty: float}
     */
    public static function processIngredientStockDeduction(
        int $businessId,
        int $outletId,
        Transaction $trx,
        string $orderNumber,
        string $date,
        Ingredient $ing,
        float $requiredQty,
        string $itemName,
        string $itemType,
        ?int $menuId,
        bool $isShiftActive,
        bool $isPaid,
        int $userId,
        ?string $unit = null
    ): array {
        $unit = $unit ?: $ing->unit_pakai ?: 'satuan';
        $costPerPakai = $ing->costPerPakaiForOutlet($outletId);

        // Calculate available stock in the target outlet
        $currentStock = (float)$ing->stockForOutlet($outletId);
        $isDeficit = ($currentStock < $requiredQty) || !empty($trx->is_urgent_note);

        // Calculate deficit / pending portion for urgent note tracking
        $pendingQty = 0.0;
        if ($isDeficit) {
            $pendingQty = max(0, round($requiredQty - max(0, $currentStock), 4));
        }

        // Deduct full required recipe quantity directly into StockMovement
        $deductedQty = $requiredQty;
        $totalCost = round($deductedQty * $costPerPakai, 2);

        // Deduct via StockMovement if order is paid/recorded
        if ($isPaid && $deductedQty > 0) {
            StockMovement::create([
                'business_id'    => $businessId,
                'outlet_id'      => $outletId,
                'ingredient_id'  => $ing->id,
                'date'           => $date,
                'type'           => 'SALE_USAGE',
                'qty'            => $deductedQty,
                'unit_price'     => $costPerPakai * max((float)$ing->konversi, 1),
                'total_price'    => $totalCost,
                'cost_before'    => $costPerPakai,
                'cost_after'     => $costPerPakai,
                'note'           => "{$orderNumber} – {$itemName}" . ($pendingQty > 0 ? " (Defisit {$pendingQty} {$unit} Nota Urgent)" : ""),
                'transaction_id' => $trx->id,
                'shift_id'       => $trx->shift_id,
                'user_id'        => $userId,
                'created_by'     => $userId,
            ]);
        }

        // Record deficit into UrgentNote table ONLY if this ingredient actually has a deficit / shortage
        if ($pendingQty > 0) {
            UrgentNote::create([
                'business_id'    => $businessId,
                'outlet_id'      => $outletId,
                'transaction_id' => $trx->id,
                'order_number'   => $orderNumber,
                'menu_id'        => $menuId,
                'ingredient_id'  => $ing->id,
                'item_type'      => $itemType,
                'item_name'      => $itemName ?: $ing->name,
                'required_qty'   => $requiredQty,
                'deducted_qty'   => min($requiredQty, max(0, $currentStock)),
                'pending_qty'    => $pendingQty,
                'unit'           => $unit,
                'status'         => 'PENDING',
                'notes'          => "Nota Urgent POS #{$orderNumber} – {$itemName} (Defisit {$pendingQty} {$unit})",
                'created_by'     => $userId,
            ]);

            if (!$trx->is_urgent_note) {
                $trx->update([
                    'is_urgent_note' => true,
                    'urgent_status'  => 'PENDING',
                ]);
            }
        }

        return [
            'is_deficit'   => $isDeficit,
            'deducted_qty' => $deductedQty,
            'pending_qty'  => $pendingQty,
        ];
    }

    /**
     * Helper to process direct retail product stock deduction with Urgent Note support.
     *
     * @param int $businessId
     * @param int $outletId
     * @param Transaction $trx
     * @param string $orderNumber
     * @param Menu $menu
     * @param int $qty
     * @param int $userId
     * @return void
     */
    public static function processDirectProductStockDeduction(
        int $businessId,
        int $outletId,
        Transaction $trx,
        string $orderNumber,
        Menu $menu,
        int $qty,
        int $userId
    ): void {
        if ($menu->item_type !== 'DIRECT' || !$menu->track_stock) {
            return;
        }

        $om = null;
        if ($outletId) {
            try {
                $om = OutletMenu::where('outlet_id', $outletId)->where('menu_id', $menu->id)->first();
            } catch (\Throwable $e) {}
        }

        $currentStock = (float)($om ? $om->stock : $menu->stock);
        $isDeficit = ($currentStock < $qty) || !empty($trx->is_urgent_note);

        $deductedQty = (float)$qty;
        $pendingQty = 0.0;

        if ($isDeficit) {
            if ($currentStock <= 0) {
                $deductedQty = 0.0;
                $pendingQty = (float)$qty;
            } else {
                $deductedQty = min((float)$qty, $currentStock);
                $pendingQty = max(0, (float)($qty - $deductedQty));
            }
        }

        if ($deductedQty > 0) {
            $menu->decrement('stock', $deductedQty);
            if ($om) {
                $om->decrement('stock', $deductedQty);
            }
        }

        if ($pendingQty > 0) {
            UrgentNote::create([
                'business_id'    => $businessId,
                'outlet_id'      => $outletId,
                'transaction_id' => $trx->id,
                'order_number'   => $orderNumber,
                'menu_id'        => $menu->id,
                'ingredient_id'  => null,
                'item_type'      => 'DIRECT',
                'item_name'      => $menu->name,
                'required_qty'   => $qty,
                'deducted_qty'   => $deductedQty,
                'pending_qty'    => $pendingQty,
                'unit'           => $menu->unit ?: 'pcs',
                'status'         => 'PENDING',
                'notes'          => "Nota Urgent POS Retail #{$orderNumber} – {$menu->name} (Defisit {$pendingQty} " . ($menu->unit ?: 'pcs') . ")",
                'created_by'     => $userId,
            ]);

            if (!$trx->is_urgent_note) {
                $trx->update([
                    'is_urgent_note' => true,
                    'urgent_status'  => 'PENDING',
                ]);
            }
        }
    }
}
