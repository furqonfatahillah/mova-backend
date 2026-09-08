<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrepRecipeItem extends Model
{
    protected $fillable = [
        'prep_recipe_id',
        'ingredient_id',
        'qty',
        'unit',
        'waste_std',
    ];

    protected $casts = [
        'qty'       => 'float',
        'waste_std' => 'float',
    ];

    protected $appends = [
        'estimated_cost',
    ];

    public function prepRecipe()
    {
        return $this->belongsTo(PrepRecipe::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * Estimated cost for this item in 1 batch based on current raw ingredient moving average / harga
     */
    public function getEstimatedCostAttribute(): float
    {
        $ing = $this->ingredient;
        if (!$ing) {
            return 0.0;
        }
        $konversi = max((float)$ing->konversi, 1);
        $costPerPakai = (float)$ing->harga / $konversi;

        // If unit matches unit_beli, convert
        $effQty = (float)$this->qty;
        if (strtolower($this->unit) === strtolower($ing->unit_beli)) {
            $effQty = $effQty * $konversi;
        }

        // Apply waste factor: qty * (1 + waste_std/100)
        $wasteFactor = 1 + ((float)$this->waste_std / 100);
        return round($effQty * $wasteFactor * $costPerPakai, 2);
    }
}
