<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Receivable extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'receivable_no',
        'business_id',
        'outlet_id',
        'transaction_id',
        'customer_id',
        'order_number',
        'customer_name',
        'customer_phone',
        'customer_address',
        'issue_date',
        'due_date',
        'total_amount',
        'paid_amount',
        'remaining_amount',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'issue_date'       => 'date:Y-m-d',
        'due_date'         => 'date:Y-m-d',
        'total_amount'     => 'float',
        'paid_amount'      => 'float',
        'remaining_amount' => 'float',
    ];

    protected $appends = [
        'outlet_name',
        'status_label',
        'is_overdue',
        'days_remaining',
        'progress_pct',
        'created_by_name',
        'updated_by_name',
    ];

    public static function generateReceivableNo(?int $businessId, string $date): string
    {
        $dateFormatted = date('Ymd', strtotime($date));
        $prefix = "PIU-{$dateFormatted}-";

        $last = self::withoutGlobalScopes()
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->where('receivable_no', 'like', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->value('receivable_no');

        $nextSeq = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $matches)) {
            $nextSeq = intval($matches[1]) + 1;
        }

        return $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
    }

    public function payments()
    {
        return $this->hasMany(ReceivablePayment::class)->orderBy('payment_date', 'desc')->orderBy('id', 'desc');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function getOutletNameAttribute(): string
    {
        return $this->outlet?->name ?? 'Semua Cabang / Pusat';
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'PAID'      => 'Lunas',
            'PARTIAL'   => 'Sebagian',
            'UNPAID'    => 'Belum Dibayar',
            'OVERDUE'   => 'Jatuh Tempo',
            'CANCELLED' => 'Dibatalkan',
            default     => $this->status ?: 'Belum Dibayar',
        };
    }

    public function getIsOverdueAttribute(): bool
    {
        if ($this->status === 'PAID' || $this->status === 'CANCELLED') {
            return false;
        }
        if (!$this->due_date) return false;
        $due = is_string($this->due_date) ? $this->due_date : $this->due_date->toDateString();
        return $due < now()->toDateString();
    }

    public function getDaysRemainingAttribute(): int
    {
        if (!$this->due_date) return 0;
        $due = is_string($this->due_date) ? strtotime($this->due_date) : $this->due_date->timestamp;
        $today = strtotime(now()->toDateString());
        return intval(round(($due - $today) / 86400));
    }

    public function getProgressPctAttribute(): float
    {
        if ($this->total_amount <= 0) return 100.0;
        return round(($this->paid_amount / $this->total_amount) * 100, 1);
    }
}
