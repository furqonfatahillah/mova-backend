<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Opname extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'opname_no', 'opname_date',
        'period_from', 'period_to', 'ingredient_id', 'outlet_id',
        'actual_qty', 'reason', 'approver', 'notes', 'is_closed', 'user_id',
        'created_by', 'updated_by',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    protected $casts = ['actual_qty' => 'float', 'is_closed' => 'boolean'];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
