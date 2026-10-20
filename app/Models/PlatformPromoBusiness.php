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
    ];

    protected $casts = [
        'usage_limit'    => 'integer',
        'used_count'     => 'integer',
        'start_date'     => 'date:Y-m-d',
        'end_date'       => 'date:Y-m-d',
        'distributed_at' => 'datetime',
    ];

    protected $appends = [
        'is_quota_full',
        'is_expired',
        'remaining_quota',
        'effective_status',
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

    public function getEffectiveStatusAttribute(): string
    {
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
