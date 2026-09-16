<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferItem extends Model
{
    protected $fillable = [
        'transfer_id',
        'item_type', // 'INGREDIENT' or 'PRODUCT'
        'ingredient_id',
        'menu_id',
        'qty',
        'unit',
        'input_qty',
        'input_unit',
        'returned_qty',
        'received_qty',
        'return_reason',
        'notes',
    ];

    protected $casts = [
        'qty'          => 'float',
        'input_qty'    => 'float',
        'returned_qty' => 'float',
        'received_qty' => 'float',
    ];

    protected $appends = [
        'item_name',
        'item_code',
        'is_product',
    ];

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function getItemNameAttribute(): string
    {
        if ($this->item_type === 'PRODUCT') {
            return $this->menu?->name ?? 'Produk #' . ($this->menu_id ?? '');
        }
        return $this->ingredient?->name ?? 'Bahan #' . ($this->ingredient_id ?? '');
    }

    public function getItemCodeAttribute(): string
    {
        if ($this->item_type === 'PRODUCT') {
            return $this->menu?->code ?? '';
        }
        return $this->ingredient?->code ?? '';
    }

    public function getIsProductAttribute(): bool
    {
        return $this->item_type === 'PRODUCT';
    }
}
