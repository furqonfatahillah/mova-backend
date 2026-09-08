<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class OperatingExpense extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'outlet_id',
        'expense_no',
        'date',
        'category',
        'name',
        'amount',
        'payment_method',
        'notes',
        'receipt_img',
        'user_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'date'   => 'date:Y-m-d',
    ];

    protected $appends = [
        'category_label',
        'outlet_name',
        'user_name',
        'payment_method_label',
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    public static function categories(): array
    {
        return [
            'SALARY'      => 'Gaji & Upah Karyawan',
            'UTILITIES'   => 'Listrik, Air & Internet',
            'GAS'         => 'Gas Masak (LPG)',
            'RENT'        => 'Sewa Tempat / Bangunan',
            'MAINTENANCE' => 'Pemeliharaan, Sanitasi & Servis',
            'MARKETING'   => 'Pemasaran & Promosi',
            'LOGISTICS'   => 'Logistik & Transportasi',
            'OTHER'       => 'Beban Operasional Lain-lain',
        ];
    }

    public static function paymentMethods(): array
    {
        return [
            'CASH'       => 'Kas Operasional / Tunai',
            'TRANSFER'   => 'Transfer Bank',
            'PETTY_CASH' => 'Kas Kecil (Petty Cash)',
            'DEBIT'      => 'Debit / Kartu',
        ];
    }

    public static function generateExpenseNo(?int $businessId, string $date): string
    {
        $dateFormatted = date('Ymd', strtotime($date));
        $prefix = "EXP-{$dateFormatted}-";

        $last = self::withoutGlobalScopes()
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->where('expense_no', 'like', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->value('expense_no');

        $nextSeq = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $matches)) {
            $nextSeq = intval($matches[1]) + 1;
        }

        return $prefix . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getCategoryLabelAttribute(): string
    {
        $cats = self::categories();
        return $cats[$this->category] ?? ($this->category ?: 'Lain-lain');
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        $methods = self::paymentMethods();
        return $methods[$this->payment_method] ?? ($this->payment_method ?: 'Tunai');
    }

    public function getOutletNameAttribute(): string
    {
        return $this->outlet?->name ?? 'Semua Cabang / Pusat';
    }

    public function getUserNameAttribute(): ?string
    {
        return $this->user?->name ?? $this->creator?->name ?? 'Admin';
    }
}
