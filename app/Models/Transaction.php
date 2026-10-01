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
        'customer_id',
        'order_type',
        'table_number',
        'payment_method',
        'payment_method_id',
        'dp_payment_method',
        'dp_reference_no',
        'is_urgent_note',
        'urgent_status',
        'notes',
        'cancellation_reason',
        'void_type',
        'cancelled_at',
        'cancelled_by',
        'void_requested_by',
        'void_requested_at',
        'void_approved_by',
        'void_approved_at',
        'void_rejected_by',
        'void_rejected_at',
        'void_reject_reason',
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

    public function scopeVoidPending($query)
    {
        return $query->where('status', 'VOID_PENDING');
    }

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'cancelled_by_name',
        'void_requested_by_name',
        'void_approved_by_name',
        'void_rejected_by_name',
        'changed_at',
        'changed_by_name',
    ];

    public function cancelledByUser()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function getCancelledByNameAttribute(): ?string
    {
        return $this->cancelledByUser?->name;
    }

    public function voidRequestedByUser()
    {
        return $this->belongsTo(User::class, 'void_requested_by');
    }

    public function getVoidRequestedByNameAttribute(): ?string
    {
        return $this->voidRequestedByUser?->name;
    }

    public function voidApprovedByUser()
    {
        return $this->belongsTo(User::class, 'void_approved_by');
    }

    public function getVoidApprovedByNameAttribute(): ?string
    {
        return $this->voidApprovedByUser?->name;
    }

    public function voidRejectedByUser()
    {
        return $this->belongsTo(User::class, 'void_rejected_by');
    }

    public function getVoidRejectedByNameAttribute(): ?string
    {
        return $this->voidRejectedByUser?->name;
    }

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
        'is_urgent_note' => 'boolean',
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

    public function paymentMethodModel()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function urgentNotes()
    {
        return $this->hasMany(UrgentNote::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
