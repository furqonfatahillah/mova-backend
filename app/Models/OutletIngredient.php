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
        'harga',
        'last_purchase_price',
    ];

    protected $casts = [
        'stok_awal'           => 'float',
        'stok_min'            => 'float',
        'harga'               => 'float',
        'last_purchase_price' => 'float',
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
