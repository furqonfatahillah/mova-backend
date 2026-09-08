<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferItem extends Model
{
    protected $fillable = [
        'transfer_id',
        'ingredient_id',
        'qty',
        'unit',
        'input_qty',
        'input_unit',
        'notes',
    ];

    protected $casts = [
        'qty'       => 'float',
        'input_qty' => 'float',
    ];

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
