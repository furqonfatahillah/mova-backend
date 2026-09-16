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
        'source_type',       // 'OUTLET', 'WAREHOUSE', 'EXTERNAL'
        'source_name',
        'source_outlet_id',
        'destination_type',  // 'OUTLET', 'WAREHOUSE', 'EXTERNAL'
        'destination_name',
        'destination_outlet_id',
        'transfer_type',     // 'INTER_OUTLET', 'INBOUND', 'OUTBOUND', 'EXTERNAL'
        'status',
        'received_at',
        'received_by',
        'received_notes',
        'returned_at',
        'returned_by',
        'return_reason',
        'return_disposition',
        'return_notes',
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
        'received_by_name',
        'returned_by_name',
        'changed_at',
        'changed_by_name',
        'source_display_name',
        'destination_display_name',
    ];

    public function sourceOutlet()
    {
        return $this->belongsTo(Outlet::class, 'source_outlet_id');
    }

    public function destinationOutlet()
    {
        return $this->belongsTo(Outlet::class, 'destination_outlet_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function returner()
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function items()
    {
        return $this->hasMany(TransferItem::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function getReceivedByNameAttribute(): ?string
    {
        return $this->receiver?->name;
    }

    public function getReturnedByNameAttribute(): ?string
    {
        return $this->returner?->name;
    }

    public function getSourceDisplayNameAttribute(): string
    {
        if ($this->sourceOutlet) {
            return $this->sourceOutlet->name;
        }
        return $this->source_name ?: 'Lokasi Asal';
    }

    public function getDestinationDisplayNameAttribute(): string
    {
        if ($this->destinationOutlet) {
            return $this->destinationOutlet->name;
        }
        return $this->destination_name ?: 'Lokasi Tujuan';
    }
}
