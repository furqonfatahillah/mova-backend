<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinTopupRequest extends Model
{
    protected $fillable = [
        'request_no',
        'business_id',
        'user_id',
        'referral_code_id',
        'amount_rupiah',
        'rate_per_coin',
        'amount_coins',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'transfer_proof_url',
        'status',
        'approved_by',
        'approved_at',
        'commission_percent_applied',
        'commission_rupiah_earned',
        'notes',
    ];

    protected $casts = [
        'amount_rupiah'              => 'float',
        'rate_per_coin'              => 'float',
        'amount_coins'               => 'float',
        'commission_percent_applied' => 'float',
        'commission_rupiah_earned'   => 'float',
        'approved_at'                => 'datetime',
        'created_at'                 => 'datetime',
        'updated_at'                 => 'datetime',
    ];

    protected $appends = [
        'is_overdue_10m',
        'is_overdue_1m',
        'minutes_pending',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function referralCode()
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function getMinutesPendingAttribute(): int
    {
        if ($this->status !== 'pending' || !$this->created_at) {
            return 0;
        }
        return (int) $this->created_at->diffInMinutes(now());
    }

    public function getIsOverdue10mAttribute(): bool
    {
        return $this->status === 'pending' && $this->minutes_pending >= 1;
    }

    public function getIsOverdue1mAttribute(): bool
    {
        return $this->status === 'pending' && $this->minutes_pending >= 1;
    }
}
