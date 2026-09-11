<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Shift extends Model
{
    use HasFactory, Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'outlet_id',
        'shift_schedule_id',
        'shift_name',
        'user_id',
        'opened_at',
        'closed_at',
        'initial_cash',
        'system_cash',
        'closing_cash',
        'cash_difference',
        'status',
        'notes',
        'closed_by',
        'created_by',
        'updated_by',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    protected function casts(): array
    {
        return [
            'opened_at'       => 'datetime',
            'closed_at'       => 'datetime',
            'initial_cash'    => 'float',
            'system_cash'     => 'float',
            'closing_cash'    => 'float',
            'cash_difference' => 'float',
        ];
    }

    public function shiftSchedule(): BelongsTo
    {
        return $this->belongsTo(ShiftSchedule::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Calculate theoretical ingredient usages across all transactions in this shift.
     * Returns an array grouped by ingredient_id with details.
     */
    public function calculateIngredientUsage(): array
    {
        $transactions = $this->transactions()
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->where('status', 'PAID')
                        ->where(function ($inner) {
                            $inner->whereNull('split_type')->orWhere('split_type', '!=', 'EQUAL');
                        });
                })->orWhere('status', 'SPLIT_CLOSED');
            })
            ->with(['menu.recipes.items.ingredient', 'modifiers.ingredient'])
            ->get();
        $usageByIngredient = [];

        foreach ($transactions as $trx) {
            $menu = $trx->menu;
            if ($menu) {
                // Find the recipe version matching transaction
                $recipe = $menu->recipes->firstWhere('version', $trx->recipe_version) 
                    ?: $menu->recipes->first();

                if ($recipe) {
                    foreach ($recipe->items as $item) {
                        $ingId = $item->ingredient_id;
                        $ing = $item->ingredient;
                        if (!$ing) continue;

                        $usedQty = (float)$item->qty * (int)$trx->qty;

                        if (!isset($usageByIngredient[$ingId])) {
                            $usageByIngredient[$ingId] = [
                                'ingredient_id'   => $ingId,
                                'ingredient_code' => $ing->code,
                                'ingredient_name' => $ing->name,
                                'unit'            => $item->unit ?: $ing->unit_pakai,
                                'total_qty'       => 0.0,
                                'harga_satuan'    => (float)$ing->harga / max((float)$ing->konversi, 1),
                                'total_cost'      => 0.0,
                            ];
                        }

                        $usageByIngredient[$ingId]['total_qty'] += $usedQty;
                        $usageByIngredient[$ingId]['total_cost'] += $usedQty * $usageByIngredient[$ingId]['harga_satuan'];
                    }
                }
            }

            // Also account for modifiers with linked ingredients
            if ($trx->relationLoaded('modifiers') || $trx->modifiers()->exists()) {
                foreach ($trx->modifiers as $mod) {
                    if (!$mod->ingredient_id || $mod->qty <= 0) continue;
                    $ingId = $mod->ingredient_id;
                    $ing = $mod->ingredient;
                    if (!$ing) continue;

                    $usedQty = (float)$mod->qty * (int)$trx->qty;

                    if (!isset($usageByIngredient[$ingId])) {
                        $usageByIngredient[$ingId] = [
                            'ingredient_id'   => $ingId,
                            'ingredient_code' => $ing->code,
                            'ingredient_name' => $ing->name,
                            'unit'            => $mod->unit ?: $ing->unit_pakai,
                            'total_qty'       => 0.0,
                            'harga_satuan'    => (float)$ing->harga / max((float)$ing->konversi, 1),
                            'total_cost'      => 0.0,
                        ];
                    }

                    $usageByIngredient[$ingId]['total_qty'] += $usedQty;
                    $usageByIngredient[$ingId]['total_cost'] += $usedQty * $usageByIngredient[$ingId]['harga_satuan'];
                }
            }
        }

        // Format rounded values
        foreach ($usageByIngredient as &$row) {
            $row['total_qty'] = round($row['total_qty'], 3);
            $row['total_cost'] = round($row['total_cost'], 2);
        }

        return array_values($usageByIngredient);
    }
}
