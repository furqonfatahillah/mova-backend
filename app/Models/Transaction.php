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
        'is_unlimited_mode',
        'order_number',
        'client_order_id',
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
        'is_open_bill',
        'is_urgent_note',
        'urgent_status',
        'notes',
        'is_deposited',
        'deposited_amount',
        'deposited_at',
        'deposit_cash_transaction_id',
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
        'qty'               => 'integer',
        'recipe_version'    => 'integer',
        'total_price'       => 'float',
        'subtotal'          => 'float',
        'discount_amount'   => 'float',
        'discount_rate'     => 'float',
        'amount_paid'       => 'float',
        'change_amount'     => 'float',
        'split_index'       => 'integer',
        'split_total'       => 'integer',
        'is_open_bill'      => 'boolean',
        'is_urgent_note'    => 'boolean',
        'is_unlimited_mode' => 'boolean',
    ];

    public function getIsUnlimitedModeAttribute(): bool
    {
        if (!empty($this->attributes['is_unlimited_mode'])) {
            return true;
        }
        if ($this->business && (bool)$this->business->is_unlimited) {
            return true;
        }
        return false;
    }

    public function isOpenBill(): bool
    {
        if (!empty($this->is_open_bill)) {
            return true;
        }
        if (!empty($this->parent_order_number)) {
            return true;
        }
        if (in_array($this->status, ['HOLD', 'SPLIT_CLOSED'])) {
            return true;
        }
        if (str_contains(strtolower($this->notes ?? ''), 'open bill')) {
            return true;
        }
        if (!empty($this->order_number) && self::where('order_number', $this->order_number)->where('is_open_bill', true)->exists()) {
            return true;
        }
        $je = JournalEntry::where('reference_type', 'TRANSACTION')
            ->where('reference_id', $this->id)
            ->first();
        if ($je && str_contains(strtolower($je->description ?? ''), 'open bill')) {
            return true;
        }
        return false;
    }

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

    public function receivable()
    {
        return $this->hasOne(Receivable::class, 'order_number', 'order_number');
    }

    public function depositCashTransaction()
    {
        return $this->belongsTo(CashTransaction::class, 'deposit_cash_transaction_id');
    }
}
