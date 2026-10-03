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
        'shift_id',
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
        'shift_name',
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

    public function shift()
    {
        return $this->belongsTo(Shift::class);
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

    public function getShiftNameAttribute(): ?string
    {
        // 1. Direct loaded relationship
        if ($this->relationLoaded('shift') && $this->shift) {
            return $this->shift->shift_name ?: "Shift #{$this->shift->id}";
        }
        // 2. Direct shift_id lookup
        if ($this->shift_id) {
            $s = Shift::find($this->shift_id);
            if ($s) {
                return $s->shift_name ?: "Shift #{$s->id}";
            }
        }
        // 3. Fallback to parent receivable's shift
        if ($this->relationLoaded('receivable') && $this->receivable?->shift) {
            return $this->receivable->shift->shift_name ?: "Shift #{$this->receivable->shift->id}";
        }
        if ($this->receivable_id) {
            $recShiftId = Receivable::where('id', $this->receivable_id)->value('shift_id');
            if ($recShiftId) {
                $s = Shift::find($recShiftId);
                if ($s) {
                    return $s->shift_name ?: "Shift #{$s->id}";
                }
            }
        }
        // 4. Fallback: match by outlet and created_at against shift hours
        if ($this->created_at && $this->outlet_id) {
            $matched = Shift::withoutGlobalScopes()
                ->where('outlet_id', $this->outlet_id)
                ->where('opened_at', '<=', $this->created_at)
                ->where(function ($q) {
                    $q->where('closed_at', '>=', $this->created_at)
                      ->orWhereNull('closed_at');
                })
                ->orderByDesc('opened_at')
                ->first();
            if ($matched) {
                return $matched->shift_name ?: "Shift #{$matched->id}";
            }
        }
        return null;
    }
}
