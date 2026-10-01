<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class UrgentNote extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'outlet_id',
        'transaction_id',
        'order_number',
        'menu_id',
        'ingredient_id',
        'item_type',
        'item_name',
        'required_qty',
        'deducted_qty',
        'pending_qty',
        'unit',
        'status',
        'notes',
        'approval_requested_by',
        'approval_requested_at',
        'requested_notes',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
        'resolution_movement_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'required_qty'          => 'float',
        'deducted_qty'          => 'float',
        'pending_qty'           => 'float',
        'approval_requested_at' => 'datetime',
        'approved_at'           => 'datetime',
        'rejected_at'           => 'datetime',
        'resolved_at'           => 'datetime',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'resolved_by_name',
        'approval_requested_by_name',
        'approved_by_name',
        'rejected_by_name',
        'outlet_name',
        'menu_name',
        'ingredient_name',
        'current_stock_available',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function getOutletNameAttribute()
    {
        return $this->outlet?->name;
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function getMenuNameAttribute()
    {
        return $this->menu?->name;
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function getIngredientNameAttribute()
    {
        return $this->ingredient?->name;
    }

    public function resolvedByUser()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function getResolvedByNameAttribute()
    {
        return $this->resolvedByUser?->name;
    }

    public function approvalRequestedByUser()
    {
        return $this->belongsTo(User::class, 'approval_requested_by');
    }

    public function getApprovalRequestedByNameAttribute()
    {
        return $this->approvalRequestedByUser?->name;
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getApprovedByNameAttribute()
    {
        return $this->approvedByUser?->name;
    }

    public function rejectedByUser()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function getRejectedByNameAttribute()
    {
        return $this->rejectedByUser?->name;
    }

    public function resolutionMovement()
    {
        return $this->belongsTo(StockMovement::class, 'resolution_movement_id');
    }

    public function getCurrentStockAvailableAttribute(): float
    {
        if ($this->ingredient_id && $this->ingredient) {
            $outletId = $this->outlet_id ?: 1;
            return $this->ingredient->stockForOutlet((int)$outletId);
        }

        if ($this->menu_id && $this->menu) {
            if ($this->outlet_id) {
                try {
                    $om = OutletMenu::where('outlet_id', $this->outlet_id)->where('menu_id', $this->menu_id)->first();
                    if ($om) return (float)$om->stock;
                } catch (\Throwable $e) {}
            }
            return (float)$this->menu->stock;
        }

        return 0.0;
    }

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeWaitingApproval($query)
    {
        return $query->where('status', 'APPROVAL_PENDING');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'RESOLVED');
    }
}
