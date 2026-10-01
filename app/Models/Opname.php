<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Opname extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'opname_no', 'opname_date',
        'period_from', 'period_to', 'ingredient_id', 'outlet_id',
        'stok_awal_periode', 'pembelian', 'pemakaian_teoritis',
        'prep_usage', 'prep_output', 'waste_qty', 'waste_value',
        'transfer_in', 'transfer_out', 'adjustment_qty',
        'stok_akhir_teoritis', 'cost_per_unit', 'nilai_teoritis',
        'actual_qty', 'nilai_aktual',
        'variance_qty', 'variance_value', 'variance_pct', 'status',
        'reason', 'approver', 'notes', 'is_closed', 'user_id',
        'created_by', 'updated_by',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    protected $casts = [
        'actual_qty'          => 'float',
        'stok_awal_periode'   => 'float',
        'pembelian'           => 'float',
        'pemakaian_teoritis'  => 'float',
        'prep_usage'          => 'float',
        'prep_output'         => 'float',
        'waste_qty'           => 'float',
        'waste_value'         => 'float',
        'transfer_in'         => 'float',
        'transfer_out'        => 'float',
        'adjustment_qty'      => 'float',
        'stok_akhir_teoritis' => 'float',
        'cost_per_unit'       => 'float',
        'nilai_teoritis'      => 'float',
        'nilai_aktual'        => 'float',
        'variance_qty'        => 'float',
        'variance_value'      => 'float',
        'variance_pct'        => 'float',
        'is_closed'           => 'boolean',
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
