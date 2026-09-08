<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class CashTransaction extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'outlet_id',
        'transaction_no',
        'date',
        'type',
        'activity_type',
        'category',
        'name',
        'amount',
        'account',
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
        'activity_label',
        'account_label',
        'outlet_name',
        'user_name',
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    public static function activityTypes(): array
    {
        return [
            'OPERATING' => 'Aktivitas Operasi (Operating)',
            'INVESTING' => 'Aktivitas Investasi (CapEx)',
            'FINANCING' => 'Aktivitas Pendanaan (Financing)',
        ];
    }

    public static function categories(): array
    {
        return [
            // INVESTING / CAPEX
            'EQUIPMENT'         => ['label' => 'Peralatan & Mesin Dapur (CapEx)', 'activity' => 'INVESTING', 'default_type' => 'OUT'],
            'RENOVATION'        => ['label' => 'Renovasi Bangunan & Interior (CapEx)', 'activity' => 'INVESTING', 'default_type' => 'OUT'],
            'FURNITURE'         => ['label' => 'Furnitur & Peralatan Saji (CapEx)', 'activity' => 'INVESTING', 'default_type' => 'OUT'],
            'TECH_POS'          => ['label' => 'Perangkat POS & IT Hardware', 'activity' => 'INVESTING', 'default_type' => 'OUT'],
            'ASSET_SALE'        => ['label' => 'Penjualan Aset Bekas (Kas Masuk)', 'activity' => 'INVESTING', 'default_type' => 'IN'],

            // FINANCING
            'CAPITAL_INJECTION' => ['label' => 'Setoran Modal Owner / Investor', 'activity' => 'FINANCING', 'default_type' => 'IN'],
            'OWNER_WITHDRAWAL'  => ['label' => 'Prive / Penarikan Dana Owner', 'activity' => 'FINANCING', 'default_type' => 'OUT'],
            'LOAN_RECEIPT'      => ['label' => 'Penerimaan Pinjaman Modal', 'activity' => 'FINANCING', 'default_type' => 'IN'],
            'LOAN_REPAYMENT'    => ['label' => 'Pembayaran Pokok Pinjaman', 'activity' => 'FINANCING', 'default_type' => 'OUT'],

            // OPERATING
            'SUPPLIER_PURCHASE' => ['label' => 'Belanja Bahan Baku Langsung', 'activity' => 'OPERATING', 'default_type' => 'OUT'],
            'OTHER_INCOME'      => ['label' => 'Pendapatan Kas Operasional Lain', 'activity' => 'OPERATING', 'default_type' => 'IN'],
            'OTHER_EXPENSE'     => ['label' => 'Biaya Kas Operasional Lain', 'activity' => 'OPERATING', 'default_type' => 'OUT'],
        ];
    }

    public static function accounts(): array
    {
        return [
            'BANK_MAIN'   => 'Rekening Bank Utama Resto',
            'CASH_DRAWER' => 'Kas Toko / Laci Kasir',
            'PETTY_CASH'  => 'Kas Kecil (Petty Cash)',
        ];
    }

    public static function generateTransactionNo(?int $businessId, string $date): string
    {
        $dateFormatted = date('Ymd', strtotime($date));
        $prefix = "CSH-{$dateFormatted}-";

        $last = self::withoutGlobalScopes()
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->where('transaction_no', 'like', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->value('transaction_no');

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
        return $cats[$this->category]['label'] ?? ($this->category ?: 'Lain-lain');
    }

    public function getActivityLabelAttribute(): string
    {
        $acts = self::activityTypes();
        return $acts[$this->activity_type] ?? ($this->activity_type ?: 'Operasi');
    }

    public function getAccountLabelAttribute(): string
    {
        $accs = self::accounts();
        return $accs[$this->account] ?? ($this->account ?: 'Bank Utama');
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
