<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToBusiness;

class ExpenseCategory extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'color',
        'icon',
    ];

    public function expenses()
    {
        return $this->hasMany(OperatingExpense::class, 'expense_category_id');
    }

    public function cashTransactions()
    {
        return $this->hasMany(CashTransaction::class, 'expense_category_id');
    }
}
