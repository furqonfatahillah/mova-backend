<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\StockMovement;
use App\Models\Ingredient;
use App\Models\WasteLog;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Fix any StockMovement with negative quantity or missing unit_price
        $negativeMovements = StockMovement::where('type', 'WASTE')
            ->where(function ($q) {
                $q->where('qty', '<', 0)
                  ->orWhere('note', 'like', '%Void Wasted Open Bill%');
            })
            ->get();

        foreach ($negativeMovements as $mov) {
            $absQty = abs((float)$mov->qty);
            $mov->qty = $absQty;

            $ing = Ingredient::find($mov->ingredient_id);
            if ($ing) {
                $outletId = $mov->outlet_id;
                $unitCost = (float)$mov->unit_price;

                if ($unitCost <= 0) {
                    $unitCost = (float)$ing->costPerPakaiForOutlet($outletId);
                    $lastMovement = StockMovement::where('ingredient_id', $ing->id)
                        ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
                        ->where('id', '!=', $mov->id)
                        ->whereIn('type', ['PURCHASE', 'TRANSFER_IN', 'INITIAL', 'PREP_OUTPUT', 'ADJUSTMENT_IN'])
                        ->latest('date')
                        ->latest('id')
                        ->first();
                    if ($lastMovement) {
                        if ((float)$lastMovement->cost_after > 0) {
                            $unitCost = (float)$lastMovement->cost_after;
                        } elseif ((float)$lastMovement->unit_price > 0) {
                            $unitCost = (float)$lastMovement->unit_price;
                        }
                    }
                    if ($unitCost <= 0) {
                        $unitCost = (float)($ing->harga / max((float)$ing->konversi, 1));
                    }
                }

                $mov->unit_price = $unitCost;
                $mov->total_price = round($absQty * $unitCost, 2);
                $mov->cost_before = $unitCost;
                $mov->cost_after = $unitCost;
            }

            $mov->save();
        }

        // Also do a blanket check for any remaining negative qty in stock_movements where type is in outTypes
        DB::statement("UPDATE stock_movements SET qty = ABS(qty) WHERE type IN ('WASTE', 'SALE_USAGE', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'PREP_USAGE') AND qty < 0");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
