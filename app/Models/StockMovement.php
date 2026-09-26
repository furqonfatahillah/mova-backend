<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class StockMovement extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'date', 'ingredient_id', 'outlet_id', 'type', 'waste_reason', 'qty',
        'unit_price', 'total_price', 'cost_before', 'cost_after',
        'note', 'transaction_id', 'shift_id', 'transfer_id', 'batch_prep_id', 'user_id',
        'payment_type', 'supplier_name', 'purchase_no', 'payable_id',
        'created_by', 'updated_by'
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
        // ⚡ PERF: outlet_name removed — triggers N+1 lazy-load on bulk queries.
        // Controllers that render movements should eager-load 'outlet' and append explicitly.
    ];

    protected $casts = [
        'qty'          => 'float',
        'unit_price'   => 'float',
        'total_price'  => 'float',
        'cost_before'  => 'float',
        'cost_after'   => 'float',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function getOutletNameAttribute()
    {
        return $this->outlet?->name;
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function batchPrep()
    {
        return $this->belongsTo(BatchPrep::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function payable()
    {
        return $this->belongsTo(Payable::class);
    }

    /** Returns +qty for IN movements, -qty for OUT movements */
    public function signedQty(): float
    {
        $outTypes = ['SALE_USAGE', 'WASTE', 'ADJUSTMENT_OUT', 'TRANSFER_OUT', 'PREP_USAGE'];
        return in_array($this->type, $outTypes) ? -$this->qty : $this->qty;
    }
}
