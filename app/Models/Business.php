<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Business extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
        'slug',
        'owner_name',
        'email',
        'phone',
        'address',
        'package_type',
        'max_outlets',
        'status',
        'referred_by_id',
        'referral_code_used',
        'coin_balance',
        'coins_per_transaction',
        'low_coin_threshold',
        'expires_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'expires_at'             => 'datetime',
        'max_outlets'            => 'integer',
        'coin_balance'           => 'float',
        'coins_per_transaction'  => 'float',
        'low_coin_threshold'     => 'integer',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
        'is_active',
        'remaining_transactions',
        'is_coin_low',
        'is_coin_out',
        'referred_by_name',
    ];

    public function coinTransactions()
    {
        return $this->hasMany(CoinTransaction::class);
    }

    public function getRemainingTransactionsAttribute(): int
    {
        $rate = (float)($this->coins_per_transaction ?: 1.00);
        if ($rate <= 0) return 999999;
        $balance = (float)($this->coin_balance ?: 0.00);
        return (int) floor($balance / $rate);
    }

    public function getIsCoinLowAttribute(): bool
    {
        $threshold = (int)($this->low_coin_threshold ?: 2000);
        return $this->remaining_transactions <= $threshold && $this->remaining_transactions > 0;
    }

    public function getIsCoinOutAttribute(): bool
    {
        return $this->remaining_transactions <= 0;
    }

    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by_id');
    }

    public function getReferredByNameAttribute(): ?string
    {
        return $this->referredBy?->name;
    }

    public function outlets()
    {
        return $this->hasMany(Outlet::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function ingredients()
    {
        return $this->hasMany(Ingredient::class);
    }

    public function menus()
    {
        return $this->hasMany(Menu::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function getOutletsCountAttribute(): int
    {
        return isset($this->attributes['outlets_count']) ? (int)$this->attributes['outlets_count'] : $this->outlets()->count();
    }

    public function getUsersCountAttribute(): int
    {
        return isset($this->attributes['users_count']) ? (int)$this->attributes['users_count'] : $this->users()->count();
    }

    public function getIsActiveAttribute(): bool
    {
        if ($this->status === 'suspended') return false;
        if ($this->status === 'expired') return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    public function canAddOutlet(): bool
    {
        return $this->outlets()->count() < $this->max_outlets;
    }
}
