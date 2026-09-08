<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Outlet extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'address',
        'phone',
        'pic_name',
        'is_main',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_main' => 'boolean',
        'active'  => 'boolean',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    public function transfersOut()
    {
        return $this->hasMany(Transfer::class, 'source_outlet_id');
    }

    public function transfersIn()
    {
        return $this->hasMany(Transfer::class, 'destination_outlet_id');
    }
}
