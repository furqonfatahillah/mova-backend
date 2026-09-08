<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Transaction extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'order_number',
        'parent_order_number',
        'split_index',
        'split_total',
        'split_type',
        'status',
        'date',
        'menu_id',
        'qty',
        'recipe_version',
        'total_price',
        'subtotal',
        'discount_id',
        'discount_amount',
        'discount_name',
        'discount_type',
        'discount_rate',
        'amount_paid',
        'change_amount',
        'customer_name',
        'order_type',
        'table_number',
        'payment_method',
        'notes',
        'user_id',
        'shift_id',
        'outlet_id',
        'created_by',
        'updated_by',
    ];

    public function scopePaid($query)
    {
        return $query->where('status', 'PAID');
    }

    public function scopeHold($query)
    {
        return $query->where('status', 'HOLD');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'CANCELLED');
    }

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    protected $casts = [
        'qty'            => 'integer',
        'recipe_version' => 'integer',
        'total_price'     => 'float',
        'subtotal'        => 'float',
        'discount_amount' => 'float',
        'discount_rate'   => 'float',
        'amount_paid'     => 'float',
        'change_amount'  => 'float',
        'split_index'    => 'integer',
        'split_total'    => 'integer',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function modifiers()
    {
        return $this->hasMany(TransactionModifier::class);
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }
}
