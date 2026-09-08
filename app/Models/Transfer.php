<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Transfer extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'transfer_no',
        'date',
        'source_outlet_id',
        'destination_outlet_id',
        'status',
        'notes',
        'driver_name',
        'vehicle_no',
        'total_items',
        'created_by',
        'updated_by',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    public function sourceOutlet()
    {
        return $this->belongsTo(Outlet::class, 'source_outlet_id');
    }

    public function destinationOutlet()
    {
        return $this->belongsTo(Outlet::class, 'destination_outlet_id');
    }

    public function items()
    {
        return $this->hasMany(TransferItem::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }
}
