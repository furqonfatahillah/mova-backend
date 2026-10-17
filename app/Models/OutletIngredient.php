<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Table 3: Saldo Berjalan & Par Level Cabang (outlet_ingredients)
 * 
 * Khusus mengelola saldo berjalan (current_stock) dan konfigurasi spesifik outlet.
 * Saldo awal dikelola terpisah di Table 2 (ingredient_initial_balances).
 */
class OutletIngredient extends Model
{
    protected $fillable = [
        'outlet_id',
        'ingredient_id',
        'current_stock',
        'stok_min',
        'harga',
        'last_purchase_price',
    ];

    protected $casts = [
        'current_stock'       => 'float',
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

    /**
     * Relationship to Table 2: Saldo Awal per Cabang
     */
    public function initialBalance()
    {
        return $this->hasOne(IngredientInitialBalance::class, 'ingredient_id', 'ingredient_id')
            ->where('outlet_id', $this->outlet_id);
    }

    // Accessors for backward compatibility: read directly from Table 2 (ingredient_initial_balances)
    public function getStokAwalAttribute(): float
    {
        return (float) ($this->initialBalance?->stok_awal ?? 0);
    }

    public function getSaldoAwalNominalAttribute(): float
    {
        return (float) ($this->initialBalance?->saldo_awal_nominal ?? 0);
    }

    public function getTanggalSaldoAwalAttribute(): ?string
    {
        return $this->initialBalance?->tanggal_saldo_awal;
    }
}
