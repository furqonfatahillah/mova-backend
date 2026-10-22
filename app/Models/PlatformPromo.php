<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class PlatformPromo extends Model
{
    use Auditable;

    protected $fillable = [
        'title',
        'code',
        'description',
        'type', // 'PERCENTAGE', 'FIXED'
        'value',
        'min_order_amount',
        'max_discount_amount',
        'default_usage_limit',
        'default_subsidy_amount',
        'start_date',
        'end_date',
        'banner_color',
        'badge_text',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'value'                  => 'float',
        'min_order_amount'       => 'float',
        'max_discount_amount'    => 'float',
        'default_usage_limit'    => 'integer',
        'default_subsidy_amount' => 'float',
        'is_active'              => 'boolean',
        'start_date'             => 'date:Y-m-d',
        'end_date'               => 'date:Y-m-d',
    ];

    protected $appends = [
        'formatted_value',
        'is_expired',
        'total_distributed',
        'total_used_count',
        'total_quota_limit',
        'created_by_name',
        'updated_by_name',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function distributions()
    {
        return $this->hasMany(PlatformPromoBusiness::class, 'platform_promo_id');
    }

    public function businesses()
    {
        return $this->belongsToMany(Business::class, 'platform_promo_businesses', 'platform_promo_id', 'business_id')
            ->withPivot(['usage_limit', 'used_count', 'start_date', 'end_date', 'status', 'distributed_at'])
            ->withTimestamps();
    }

    public function discounts()
    {
        return $this->hasMany(Discount::class, 'platform_promo_id');
    }

    public function getFormattedValueAttribute(): string
    {
        if ($this->type === 'PERCENTAGE') {
            return rtrim(rtrim(number_format($this->value, 2, '.', ''), '0'), '.') . '%';
        }
        return 'Rp ' . number_format($this->value, 0, ',', '.');
    }

    public function getIsExpiredAttribute(): bool
    {
        if (!$this->end_date) {
            return false;
        }
        return now()->toDateString() > $this->end_date->format('Y-m-d');
    }

    public function getTotalDistributedAttribute(): int
    {
        return $this->distributions()->count();
    }

    public function getTotalUsedCountAttribute(): int
    {
        return (int) $this->distributions()->sum('used_count');
    }

    public function getTotalQuotaLimitAttribute(): int
    {
        return (int) $this->distributions()->sum('usage_limit');
    }
}
