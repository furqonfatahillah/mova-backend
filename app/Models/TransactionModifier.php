<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionModifier extends Model
{
    protected $fillable = [
        'transaction_id',
        'modifier_option_id',
        'group_name',
        'name',
        'price',
        'ingredient_id',
        'qty',
        'unit',
        'total_price',
    ];

    protected $casts = [
        'price'       => 'float',
        'qty'         => 'float',
        'total_price' => 'float',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function modifierOption()
    {
        return $this->belongsTo(ModifierOption::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
