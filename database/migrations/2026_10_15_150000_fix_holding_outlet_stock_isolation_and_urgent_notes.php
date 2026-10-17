<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Ingredient;
use App\Models\Outlet;
use App\Models\OutletIngredient;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\UrgentNote;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ensure all outlet_ingredients records exist for every ingredient and outlet
        $outlets = Outlet::all();
        $ingredients = Ingredient::all();

        foreach ($ingredients as $ing) {
            $totalConsolidated = 0.0;
            foreach ($outlets as $ot) {
                $oi = OutletIngredient::firstOrCreate(
                    ['outlet_id' => $ot->id, 'ingredient_id' => $ing->id],
                    ['stok_awal' => 0, 'current_stock' => 0]
                );

                $movNet = (float) DB::table('stock_movements')
                    ->where('ingredient_id', $ing->id)
                    ->where('outlet_id', $ot->id)
                    ->where('type', '!=', 'INITIAL')
                    ->selectRaw("SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                    ->value('net_qty') ?? 0.0;

                $initialStock = (float) $oi->stok_awal;
                // If this is holding outlet and initialStock is 0, check ing stok_awal
                if ($initialStock <= 0 && ($ot->is_main || $ot->id == 1)) {
                    $initialStock = (float) $ing->stok_awal;
                }

                $calculatedStock = round($initialStock + $movNet, 3);
                $oi->current_stock = $calculatedStock;
                $oi->save();

                $totalConsolidated += $calculatedStock;
            }

            // Update ingredient level consolidated stock
            $ing->current_stock = round($totalConsolidated, 3);
            $ing->saveQuietly();
        }

        // 2. Heal past unflagged deficit transactions from the last 14 days
        try {
            $unflaggedTrxs = Transaction::with(['menu.recipes.items.ingredient', 'movements'])
                ->where('is_urgent_note', false)
                ->whereDoesntHave('urgentNotes')
                ->whereDate('created_at', '>=', now()->subDays(14)->toDateString())
                ->whereHas('movements', function ($mq) {
                    $mq->where('note', 'like', '%Defisit%')
                      ->orWhereHas('ingredient.outletIngredients', function ($oiq) {
                          $oiq->where('current_stock', '<', 0);
                      });
                })
                ->get();

            foreach ($unflaggedTrxs as $trx) {
                $menu = $trx->menu;
                if (!$menu) continue;
                $dateStr = $trx->date ? (is_string($trx->date) ? substr($trx->date, 0, 10) : $trx->date->format('Y-m-d')) : now()->toDateString();
                $recipe = $menu->activeRecipe($dateStr);
                $qty = (int)($trx->qty ?: 1);
                $createdAny = false;

                if ($recipe && $recipe->items && $recipe->items->count() > 0) {
                    foreach ($recipe->items as $item) {
                        $ing = $item->ingredient;
                        if (!$ing) continue;
                        $reqQty = (float)($item->qty * $qty);
                        $unit = $item->unit ?: $ing->unit_pakai ?: 'satuan';
                        $targetOt = $trx->outlet_id ?: 1;
                        $currentStock = (float)$ing->stockForOutlet($targetOt);

                        if ($currentStock < $reqQty || $currentStock < 0) {
                            $deducted = max(0, min($reqQty, max(0, $currentStock + $reqQty)));
                            $pending = max(0, round($reqQty - $deducted, 4));
                            if ($pending <= 0) {
                                $pending = $reqQty;
                            }

                            UrgentNote::create([
                                'business_id'    => $trx->business_id ?: $menu->business_id ?: 1,
                                'outlet_id'      => $trx->outlet_id,
                                'transaction_id' => $trx->id,
                                'order_number'   => $trx->order_number ?: ('TRX-' . $trx->id),
                                'menu_id'        => $menu->id,
                                'ingredient_id'  => $ing->id,
                                'item_type'      => 'RECIPE',
                                'item_name'      => $menu->name . ' - ' . $ing->name,
                                'required_qty'   => $reqQty,
                                'deducted_qty'   => $deducted,
                                'pending_qty'    => $pending,
                                'unit'           => $unit,
                                'status'         => 'PENDING',
                                'notes'          => "Nota Urgent POS (Auto-Healed) #{$trx->order_number} – {$menu->name} (Defisit {$pending} {$unit})",
                                'created_by'     => $trx->user_id,
                            ]);
                            $createdAny = true;
                        }
                    }
                }

                if ($createdAny) {
                    $trx->update([
                        'is_urgent_note' => true,
                        'urgent_status'  => 'PENDING',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Non-blocking
        }
    }

    public function down(): void
    {
        // Safe to leave data intact
    }
};
