<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'outlet_id',
        'account_type',
        'bank_name',
        'bank_code',
        'account_number',
        'account_holder',
        'branch',
        'qr_image_url',
        'is_primary',
        'is_active',
        'verification_status',
        'payout_schedule',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
