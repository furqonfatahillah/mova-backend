<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class IngredientInitialBalance extends Model
{
    protected $table = 'ingredient_initial_balances';

    protected $fillable = [
        'business_id',
        'outlet_id',
        'ingredient_id',
        'stok_awal',
        'saldo_awal_nominal',
        'tanggal_saldo_awal',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'stok_awal'          => 'float',
        'saldo_awal_nominal' => 'float',
        'tanggal_saldo_awal' => 'string',
    ];

    protected static function booted(): void
    {
        static::saved(function (IngredientInitialBalance $init) {
            // 1. Sync to Table 3 (outlet_ingredients - Saldo Berjalan)
            $oi = OutletIngredient::firstOrNew([
                'outlet_id'     => $init->outlet_id,
                'ingredient_id' => $init->ingredient_id,
            ]);

            // Calculate current running stock = stok_awal + net_movements
            $netMov = (float) DB::table('stock_movements')
                ->where('ingredient_id', $init->ingredient_id)
                ->where('outlet_id', $init->outlet_id)
                ->where('type', '!=', 'INITIAL')
                ->selectRaw("SUM(CASE WHEN type IN ('PURCHASE','TRANSFER_IN','ADJUSTMENT_IN','ADJUSTMENT_PLUS','PREP_OUTPUT') THEN qty ELSE -qty END) as net_qty")
                ->value('net_qty') ?? 0.0;

            $oi->current_stock = round((float)$init->stok_awal + $netMov, 3);
            $oi->saveQuietly();
        });
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
