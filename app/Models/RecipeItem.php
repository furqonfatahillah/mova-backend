<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeItem extends Model
{
    protected $fillable = ['recipe_id', 'ingredient_id', 'qty', 'unit', 'waste_std'];

    protected $casts = ['qty' => 'float', 'waste_std' => 'float'];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }
}
