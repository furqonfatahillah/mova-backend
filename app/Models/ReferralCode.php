<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralCode extends Model
{
    protected $fillable = [
        'code',
        'recipient_name',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'whatsapp_number',
        'commission_percent',
        'total_rupiah_topup',
        'total_commission_earned',
        'total_commission_paid',
        'description',
        'max_uses',
        'times_used',
        'is_active',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'expires_at'              => 'datetime',
        'max_uses'                => 'integer',
        'times_used'              => 'integer',
        'commission_percent'      => 'float',
        'total_rupiah_topup'      => 'float',
        'total_commission_earned' => 'float',
        'total_commission_paid'   => 'float',
    ];

    protected $appends = [
        'status_label',
        'remaining_uses',
        'is_valid',
        'remaining_commission',
        'payment_status',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usages()
    {
        return $this->hasMany(ReferralUsage::class)->latest('registered_at');
    }

    public function businesses()
    {
        return $this->hasMany(Business::class, 'referral_code_id');
    }

    public function topupRequests()
    {
        return $this->hasMany(CoinTopupRequest::class, 'referral_code_id');
    }

    public function payouts()
    {
        return $this->hasMany(ReferralCommissionPayout::class)->latest('payment_date')->latest('id');
    }

    public function getRemainingCommissionAttribute(): float
    {
        return max(0.0, round((float)$this->total_commission_earned - (float)$this->total_commission_paid, 2));
    }

    public function getPaymentStatusAttribute(): string
    {
        $earned = (float)$this->total_commission_earned;
        $paid = (float)$this->total_commission_paid;

        if ($earned <= 0) {
            return 'NO_COMMISSION';
        }
        if ($paid >= $earned) {
            return 'PAID';
        }
        if ($paid > 0) {
            return 'PARTIAL';
        }
        return 'UNPAID';
    }

    public function getIsValidAttribute(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->times_used >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function getRemainingUsesAttribute(): ?int
    {
        if ($this->max_uses === null) {
            return null; // Unlimited
        }
        return max(0, $this->max_uses - $this->times_used);
    }

    public function getStatusLabelAttribute(): string
    {
        if (!$this->is_active) {
            return 'Nonaktif';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'Kedaluwarsa';
        }
        if ($this->max_uses !== null && $this->times_used >= $this->max_uses) {
            return 'Kuota Habis';
        }
        return 'Aktif';
    }
}
