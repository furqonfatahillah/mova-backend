<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralUsage extends Model
{
    protected $fillable = [
        'referral_code_id',
        'business_id',
        'user_id',
        'registered_at',
        'ip_address',
        'notes',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
    ];

    public function referralCode()
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
