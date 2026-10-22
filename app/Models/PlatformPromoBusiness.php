<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformPromoBusiness extends Model
{
    protected $table = 'platform_promo_businesses';

    protected $fillable = [
        'platform_promo_id',
        'business_id',
        'discount_id',
        'usage_limit',
        'used_count',
        'start_date',
        'end_date',
        'status', // 'ACTIVE', 'EXPIRED', 'QUOTA_DEPLETED', 'REVOKED'
        'notes',
        'distributed_at',
        'distributed_by',
        'approval_status', // 'PENDING', 'ACCEPTED', 'REJECTED'
        'approved_at',
        'approved_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'amount_payable',
        'amount_paid',
        'payment_status', // 'UNPAID', 'PARTIALLY_PAID', 'PAID'
        'last_payment_date',
        'payment_notes',
    ];

    protected $casts = [
        'usage_limit'       => 'integer',
        'used_count'        => 'integer',
        'amount_payable'    => 'float',
        'amount_paid'       => 'float',
        'start_date'        => 'date:Y-m-d',
        'end_date'          => 'date:Y-m-d',
        'distributed_at'    => 'datetime',
        'approved_at'       => 'datetime',
        'rejected_at'       => 'datetime',
        'last_payment_date' => 'date:Y-m-d',
    ];

    protected $appends = [
        'is_quota_full',
        'is_expired',
        'remaining_quota',
        'effective_status',
        'remaining_payment',
        'approver_name',
        'rejector_name',
    ];

    public function platformPromo()
    {
        return $this->belongsTo(PlatformPromo::class, 'platform_promo_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function distributor()
    {
        return $this->belongsTo(User::class, 'distributed_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function payments()
    {
        return $this->hasMany(PlatformPromoPayment::class, 'platform_promo_business_id')->orderByDesc('payment_date');
    }

    public function getIsQuotaFullAttribute(): bool
    {
        return $this->usage_limit > 0 && $this->used_count >= $this->usage_limit;
    }

    public function getIsExpiredAttribute(): bool
    {
        if (!$this->end_date) {
            return false;
        }
        return now()->toDateString() > $this->end_date->format('Y-m-d');
    }

    public function getRemainingQuotaAttribute(): int
    {
        return max(0, $this->usage_limit - $this->used_count);
    }

    public function getRemainingPaymentAttribute(): float
    {
        return max(0, (float)$this->amount_payable - (float)$this->amount_paid);
    }

    public function getApproverNameAttribute(): ?string
    {
        return $this->approver?->name;
    }

    public function getRejectorNameAttribute(): ?string
    {
        return $this->rejector?->name;
    }

    public function getEffectiveStatusAttribute(): string
    {
        if ($this->approval_status === 'REJECTED') {
            return 'REJECTED';
        }
        if ($this->approval_status === 'PENDING') {
            return 'PENDING_APPROVAL';
        }
        if ($this->status === 'REVOKED') {
            return 'REVOKED';
        }
        if ($this->is_expired) {
            return 'EXPIRED';
        }
        if ($this->is_quota_full) {
            return 'QUOTA_DEPLETED';
        }
        return 'ACTIVE';
    }
}
