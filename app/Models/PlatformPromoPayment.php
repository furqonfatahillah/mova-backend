<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformPromoPayment extends Model
{
    protected $table = 'platform_promo_payments';

    protected $fillable = [
        'platform_promo_business_id',
        'amount',
        'payment_date',
        'payment_method',
        'reference_no',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount'       => 'float',
        'payment_date' => 'date:Y-m-d',
    ];

    public function distribution()
    {
        return $this->belongsTo(PlatformPromoBusiness::class, 'platform_promo_business_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
