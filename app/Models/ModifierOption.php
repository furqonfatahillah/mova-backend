<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModifierOption extends Model
{
    protected $fillable = [
        'modifier_group_id',
        'name',
        'price',
        'ingredient_id',
        'qty',
        'unit',
        'sort_order',
    ];

    protected $casts = [
        'price'      => 'float',
        'qty'        => 'float',
        'sort_order' => 'integer',
    ];

    protected $appends = [
        'ingredient_name',
    ];

    public function group()
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function getIngredientNameAttribute(): ?string
    {
        return $this->ingredient?->name;
    }
}
