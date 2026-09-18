<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToBusiness;

class PaymentMethod extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'type', // CASH, QRIS, TRANSFER, DEBIT, OTHER
        'active',
        'sort_order',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'sort_order' => 'integer',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'payment_method_id');
    }

    public function operatingExpenses()
    {
        return $this->hasMany(OperatingExpense::class, 'payment_method_id');
    }

    public function cashTransactions()
    {
        return $this->hasMany(CashTransaction::class, 'payment_method_id');
    }
}
