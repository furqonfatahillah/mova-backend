<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\StockMovement;
use App\Models\WasteLog;
use App\Models\Transaction;
use App\Models\Menu;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update any StockMovement that belongs to a VOID WASTED transaction
        $wastedMovements = StockMovement::where(function ($q) {
            $q->where('note', 'like', '%VOID WASTED%')
              ->orWhere('note', 'like', '%[VOID WASTED%');
        })->get();

        foreach ($wastedMovements as $mov) {
            $mov->type = 'WASTE';
            $mov->waste_reason = 'CUSTOMER_COMPLAINT';
            $mov->save();
        }

        // Also check by transaction status
        $cancelledWastedTrxs = Transaction::where('status', 'CANCELLED')
            ->where('void_type', 'WASTED')
            ->get();

        foreach ($cancelledWastedTrxs as $t) {
            StockMovement::where('transaction_id', $t->id)
                ->orWhere('note', 'like', "{$t->order_number}%")
                ->update([
                    'type'         => 'WASTE',
                    'waste_reason' => 'CUSTOMER_COMPLAINT',
                ]);
        }

        // Delete any movements for WRONG_INPUT cancelled transactions
        $cancelledWrongTrxs = Transaction::where('status', 'CANCELLED')
            ->where('void_type', 'WRONG_INPUT')
            ->get();

        foreach ($cancelledWrongTrxs as $t) {
            StockMovement::where(function ($q) use ($t) {
                $q->where('transaction_id', $t->id)
                  ->orWhere('note', 'like', "{$t->order_number} – %")
                  ->orWhere('note', 'like', "{$t->order_number}%");
            })->delete();
        }

        // 2. Fix WasteLog loss_cost and cost_per_unit to use real ingredient costs
        $voidWasteLogs = WasteLog::where(function ($q) {
            $q->where('notes', 'like', '%Void Wasted%')
              ->orWhere('notes', 'like', '%Makanan Batal%');
        })->get();

        foreach ($voidWasteLogs as $wLog) {
            $orderNum = null;
            if (preg_match('/Nota #([A-Za-z0-9\-]+)/', $wLog->notes, $matches)) {
                $orderNum = $matches[1];
            }

            if ($orderNum) {
                $trxs = Transaction::where('order_number', $orderNum)->get();
                $trxIds = $trxs->pluck('id')->all();

                $realMovCost = (float)StockMovement::whereIn('transaction_id', $trxIds)
                    ->orWhere('note', 'like', "{$orderNum}%")
                    ->sum('total_price');

                if ($realMovCost > 0) {
                    $qty = (float)$wLog->qty > 0 ? (float)$wLog->qty : 1.0;
                    $wLog->loss_cost = round($realMovCost, 2);
                    $wLog->cost_per_unit = round($realMovCost / $qty, 2);
                    $wLog->save();
                } else {
                    // Fallback calculate recipe HPP
                    if ($wLog->menu_id) {
                        $menu = Menu::find($wLog->menu_id);
                        if ($menu && method_exists($menu, 'calculateHpp')) {
                            $hpp = (float)$menu->calculateHpp($wLog->date, $wLog->outlet_id);
                            if ($hpp > 0) {
                                $qty = (float)$wLog->qty > 0 ? (float)$wLog->qty : 1.0;
                                $wLog->cost_per_unit = round($hpp, 2);
                                $wLog->loss_cost = round($hpp * $qty, 2);
                                $wLog->save();
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op rollback
    }
};
