<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutletIngredient extends Model
{
    protected $fillable = [
        'outlet_id',
        'ingredient_id',
        'stok_awal',
        'stok_min',
    ];

    protected $casts = [
        'stok_awal' => 'float',
        'stok_min'  => 'float',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
