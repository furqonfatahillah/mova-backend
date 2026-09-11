<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoinTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'payment_amount',
        'payment_reference',
        'order_number',
        'outlet_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount'         => 'float',
        'balance_before' => 'float',
        'balance_after'  => 'float',
        'payment_amount' => 'float',
    ];

    protected $appends = [
        'created_by_name',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getCreatedByNameAttribute(): ?string
    {
        return $this->creator?->name;
    }
}
