<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\OutletIngredient;

return new class extends Migration
{
    /**
     * Enforces strict One-to-Many architecture:
     * 1 Master Ingredient -> N OutletIngredient initial balances and stocks.
     */
    public function up(): void
    {
        $outlets = Outlet::all();
        $ingredients = Ingredient::all();

        foreach ($ingredients as $ing) {
            foreach ($outlets as $ot) {
                $oi = OutletIngredient::firstOrNew([
                    'outlet_id'     => $ot->id,
                    'ingredient_id' => $ing->id,
                ]);

                $isNew = !$oi->exists;

                if ($isNew) {
                    $oi->stok_awal = 0.0;
                    $oi->saldo_awal_nominal = 0.0;
                    $oi->tanggal_saldo_awal = $ing->tanggal_saldo_awal ?: now()->toDateString();
                    $oi->stok_min = (float)($ing->stok_min ?? 0);
                    $oi->harga = (float)($ing->harga ?? 0);
                    $oi->last_purchase_price = (float)($ing->last_purchase_price ?? $ing->harga ?? 0);
                }

                // Calculate net movements specifically for this outlet
                $netMov = (float) DB::table('stock_movements')
                    ->where('ingredient_id', $ing->id)
                    ->where('outlet_id', $ot->id)
                    ->where('type', '!=', 'INITIAL')
                    ->selectRaw("SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                    ->value('net_qty') ?? 0.0;

                $stokAwal = (float)($oi->stok_awal ?? 0);
                $oi->current_stock = round($stokAwal + $netMov, 3);
                $oi->save();
            }

            // Consolidated sum across all outlets for enterprise level reporting
            $allOi = OutletIngredient::where('ingredient_id', $ing->id)->get();
            $ing->current_stock = round($allOi->sum('current_stock'), 3);
            $ing->stok_awal = round($allOi->sum('stok_awal'), 3);
            $ing->saldo_awal_nominal = round($allOi->sum('saldo_awal_nominal'), 2);
            $ing->saveQuietly();
        }
    }

    public function down(): void
    {
        // No-op rollback
    }
};
