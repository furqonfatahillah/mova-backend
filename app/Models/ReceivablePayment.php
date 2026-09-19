<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToBusiness;

class ReceivablePayment extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'payment_no',
        'receivable_id',
        'business_id',
        'outlet_id',
        'payment_date',
        'amount',
        'payment_method',
        'reference_no',
        'notes',
        'received_by',
    ];

    protected $casts = [
        'payment_date' => 'date:Y-m-d',
        'amount'       => 'float',
    ];

    protected $appends = [
        'received_by_name',
        'outlet_name',
    ];

    public static function generatePaymentNo(?int $businessId, string $date): string
    {
        $dateFormatted = date('Ymd', strtotime($date));
        $prefix = "PAY-PIU-{$dateFormatted}-";

        $last = self::withoutGlobalScopes()
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->where('payment_no', 'like', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->value('payment_no');

        $nextSeq = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $matches)) {
            $nextSeq = intval($matches[1]) + 1;
        }

        return $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
    }

    public function receivable()
    {
        return $this->belongsTo(Receivable::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function getReceivedByNameAttribute(): string
    {
        return $this->receiver?->name ?? 'Kasir / Staf';
    }

    public function getOutletNameAttribute(): string
    {
        return $this->outlet?->name ?? 'Pusat';
    }
}
