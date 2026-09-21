<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Discount extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'code',
        'type', // 'PERCENTAGE' or 'FIXED'
        'value',
        'requires_points',
        'reward_type', // 'DISCOUNT' or 'FREE_MENU'
        'reward_menu_id',
        'scope', // 'TRANSACTION', 'CATEGORY', 'MENU_ITEM'
        'scope_target_id',
        'min_order_amount',
        'max_discount_amount',
        'start_date',
        'end_date',
        'usage_limit',
        'used_count',
        'outlet_id',
        'is_auto_apply',
        'active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'value'               => 'float',
        'requires_points'     => 'integer',
        'min_order_amount'    => 'float',
        'max_discount_amount' => 'float',
        'is_auto_apply'       => 'boolean',
        'active'              => 'boolean',
        'usage_limit'         => 'integer',
        'used_count'          => 'integer',
        'start_date'          => 'date:Y-m-d',
        'end_date'            => 'date:Y-m-d',
    ];

    protected $appends = [
        'formatted_value',
        'is_quota_full',
        'created_by_name',
        'updated_by_name',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function rewardMenu()
    {
        return $this->belongsTo(Menu::class, 'reward_menu_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeValidNow($query, $date = null)
    {
        $d = $date ?: date('Y-m-d');
        return $query->where('active', true)
            ->where(function ($q) use ($d) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $d);
            })
            ->where(function ($q) use ($d) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $d);
            })
            ->where(function ($q) {
                $q->whereNull('usage_limit')->orWhereRaw('used_count < usage_limit');
            });
    }

    public function getFormattedValueAttribute(): string
    {
        if ($this->reward_type === 'FREE_MENU' && $this->relationLoaded('rewardMenu') && $this->rewardMenu) {
            return 'Free ' . $this->rewardMenu->name;
        }
        if ($this->type === 'PERCENTAGE') {
            return rtrim(rtrim(number_format($this->value, 2, '.', ''), '0'), '.') . '%';
        }
        return 'Rp ' . number_format($this->value, 0, ',', '.');
    }

    public function getIsQuotaFullAttribute(): bool
    {
        if ($this->usage_limit === null) return false;
        return $this->used_count >= $this->usage_limit;
    }

    /**
     * Check if this discount is valid for a given order subtotal, outlet, date, and optional customer.
     */
    public function validateForOrder(float $subtotal, ?int $outletId = null, ?string $date = null, ?Customer $customer = null): array
    {
        if (!$this->active) {
            return ['valid' => false, 'message' => "Promo '{$this->name}' saat ini tidak aktif."];
        }

        $d = $date ?: date('Y-m-d');
        if ($this->start_date && $d < $this->start_date->format('Y-m-d')) {
            return ['valid' => false, 'message' => "Promo '{$this->name}' baru berlaku mulai tanggal " . $this->start_date->format('d/m/Y') . "."];
        }

        if ($this->end_date && $d > $this->end_date->format('Y-m-d')) {
            return ['valid' => false, 'message' => "Promo '{$this->name}' telah berakhir pada tanggal " . $this->end_date->format('d/m/Y') . "."];
        }

        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return ['valid' => false, 'message' => "Kuota pemakaian promo '{$this->name}' telah habis ({$this->used_count}/{$this->usage_limit})."];
        }

        if ($this->outlet_id && $outletId && $this->outlet_id !== $outletId) {
            return ['valid' => false, 'message' => "Promo '{$this->name}' hanya berlaku di cabang " . ($this->outlet?->name ?? 'tertentu') . "."];
        }

        if ($this->min_order_amount > 0 && $subtotal < $this->min_order_amount) {
            $formattedMin = 'Rp ' . number_format($this->min_order_amount, 0, ',', '.');
            return ['valid' => false, 'message' => "Minimal belanja {$formattedMin} untuk menggunakan promo '{$this->name}'."];
        }

        // Validate member points requirement
        if ($this->requires_points && $this->requires_points > 0) {
            if (!$customer) {
                return [
                    'valid' => false,
                    'message' => "Promo '{$this->name}' memerlukan penukaran {$this->requires_points} poin member. Pilih member terlebih dahulu."
                ];
            }
            if (!$customer->hasEnoughPoints($this->requires_points)) {
                return [
                    'valid' => false,
                    'message' => "Poin member {$customer->name} tidak mencukupi ({$customer->total_points}/{$this->requires_points} poin)."
                ];
            }
        }

        return ['valid' => true, 'message' => 'Promo valid'];
    }

    /**
     * Calculate nominal discount amount based on order subtotal.
     */
    public function calculateDiscountAmount(float $subtotal): float
    {
        if ($subtotal <= 0) return 0.0;

        if ($this->type === 'PERCENTAGE') {
            $disc = ($subtotal * ($this->value / 100.0));
            if ($this->max_discount_amount !== null && $this->max_discount_amount > 0) {
                $disc = min($disc, $this->max_discount_amount);
            }
            return round(min($disc, $subtotal), 2);
        }

        // FIXED amount
        return round(min($this->value, $subtotal), 2);
    }
}
