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
        'shift_id',
        'expense_no',
        'date',
        'category',
        'expense_category_id',
        'name',
        'amount',
        'payment_method',
        'payment_method_id',
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

    public static function categories(?int $businessId = null): array
    {
        $businessId = $businessId ?: 1;
        $result = [];

        // Ambil akun beban operasional aktif langsung dari Master Akun (COA)
        try {
            $accounts = Account::where('business_id', $businessId)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where('code', 'like', '6-%')
                      ->orWhere('category', 'OPEX')
                      ->orWhere('category', 'OTHER_EXPENSE');
                })
                ->where('code', 'not like', '5-%')
                ->whereNull('parent_code')
                ->orderBy('code', 'asc')
                ->get(['code', 'name']);

            foreach ($accounts as $acc) {
                $result[$acc->code] = "[{$acc->code}] {$acc->name}";
            }
        } catch (\Throwable $e) {
            // fallback jika belum migrasi/koneksi db
        }

        // Mapping legacy untuk data lama / backward compatibility
        $legacy = [
            'SALARY'      => '[6-61001] Gaji & Upah Karyawan',
            'UTILITIES'   => '[6-61002] Listrik, Air & Internet',
            'GAS'         => '[6-61004] Gas Masak (LPG)',
            'RENT'        => '[6-61011] Sewa Tempat / Bangunan',
            'MAINTENANCE' => '[6-61005] Pemeliharaan, Sanitasi & Servis',
            'MARKETING'   => '[6-61006] Pemasaran & Promosi',
            'LOGISTICS'   => '[6-61007] Logistik & Transportasi',
            'ADMIN_FEE'   => '[6-61010] Biaya Admin Kas Aplikasi & Merchant',
            'OTHER'       => '[6-61009] Beban Operasional Lain-lain',
        ];

        foreach ($legacy as $k => $v) {
            if (!isset($result[$k])) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    public static function coaCategories(?int $businessId = null): array
    {
        $businessId = $businessId ?: 1;
        $accounts = Account::where('business_id', $businessId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('code', 'like', '6-%')
                  ->orWhere('category', 'OPEX')
                  ->orWhere('category', 'OTHER_EXPENSE');
            })
            ->where('code', 'not like', '5-%')
            ->whereNull('parent_code')
            ->orderBy('code', 'asc')
            ->get(['id', 'code', 'name', 'type', 'category']);

        $list = [];
        foreach ($accounts as $acc) {
            $list[] = [
                'id'       => $acc->id,
                'code'     => $acc->code,
                'value'    => $acc->code,
                'name'     => $acc->name,
                'label'    => "[{$acc->code}] {$acc->name}",
                'category' => $acc->category,
            ];
        }

        return $list;
    }

    public static function paymentMethods(): array
    {
        return [
            'CASH'       => 'Kas Operasional / Kas Kecil (Tunai)',
            'TRANSFER'   => 'Transfer Bank',
            'PETTY_CASH' => 'Kas Operasional / Kas Kecil (Tunai)',
            'DEBIT'      => 'Debit / Kartu EDC',
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

    public function expenseCategory()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function paymentMethodModel()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getCategoryLabelAttribute(): string
    {
        $cats = self::categories($this->business_id ?? 1);
        if (isset($cats[$this->category])) {
            return $cats[$this->category];
        }

        $acc = Account::where('code', $this->category)->first();
        if ($acc) {
            return "[{$acc->code}] {$acc->name}";
        }

        return $this->category ?: 'Lain-lain';
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
