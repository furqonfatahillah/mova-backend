<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentGatewaySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'active_gateway',
        'environment',
        'enable_qris',
        'enable_va',
        'midtrans_server_key',
        'midtrans_client_key',
        'midtrans_merchant_id',
        'xendit_secret_key',
        'xendit_public_key',
        'xendit_webhook_token',
        'qris_fee_absorbed_by',
        'va_fee_absorbed_by',
        'auto_settlement',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'enable_qris'     => 'boolean',
        'enable_va'       => 'boolean',
        'auto_settlement' => 'boolean',
        'last_tested_at'  => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
