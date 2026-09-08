<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class PrepRecipe extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'ingredient_id',
        'name',
        'output_qty',
        'output_unit',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'output_qty' => 'float',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
        'estimated_batch_cost',
        'estimated_unit_cost',
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function items()
    {
        return $this->hasMany(PrepRecipeItem::class);
    }

    public function batchPreps()
    {
        return $this->hasMany(BatchPrep::class);
    }

    /**
     * Estimated total raw material cost for 1 standard batch
     */
    public function getEstimatedBatchCostAttribute(): float
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += (float)$item->estimated_cost;
        }
        return round($total, 2);
    }

    /**
     * Estimated cost per 1 unit output (HPP per unit olahan)
     */
    public function getEstimatedUnitCostAttribute(): float
    {
        $batchCost = $this->estimated_batch_cost;
        $outputQty = (float)$this->output_qty;
        if ($outputQty <= 0) return 0.0;
        return round($batchCost / $outputQty, 4);
    }
}
