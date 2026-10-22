<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralCommissionPayout extends Model
{
    protected $fillable = [
        'referral_code_id',
        'payout_no',
        'amount',
        'payment_method',
        'bank_name',
        'account_number',
        'account_name',
        'payment_date',
        'reference_no',
        'notes',
        'proof_image',
        'paid_by',
    ];

    protected $casts = [
        'amount'       => 'float',
        'payment_date' => 'date',
    ];

    public function referralCode()
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
