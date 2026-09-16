<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class WasteLog extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'waste_no',
        'date',
        'item_type',
        'ingredient_id',
        'menu_id',
        'outlet_id',
        'shift_id',
        'qty',
        'unit_type',
        'qty_pakai',
        'cost_per_unit',
        'loss_cost',
        'reason_category',
        'notes',
        'action_taken',
        'user_id',
        'stock_movement_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'qty'           => 'float',
        'qty_pakai'     => 'float',
        'cost_per_unit' => 'float',
        'loss_cost'     => 'float',
    ];

    protected $appends = [
        'reason_label',
        'ingredient_name',
        'menu_name',
        'item_name',
        'unit_pakai',
        'outlet_name',
        'reporter_name',
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    public static function reasonCategories(): array
    {
        return [
            'EXPIRED'            => 'Basi / Kedaluwarsa',
            'COOKING_ERROR'      => 'Gosong / Salah Masak',
            'DELIVERY_DAMAGE'    => 'Rusak saat Pengiriman',
            'CUSTOMER_COMPLAINT' => 'Komplain Tamu / Retur',
            'DROPPED_SPILL'      => 'Tumpah / Jatuh',
            'STORAGE_DAMAGE'     => 'Rusak Penyimpanan / Chiller Mati',
            'OTHER'              => 'Lainnya',
        ];
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class);
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

    public function stockMovement()
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function getReasonLabelAttribute(): string
    {
        $categories = self::reasonCategories();
        return $categories[$this->reason_category] ?? ($this->reason_category ?: 'Lainnya');
    }

    public function getIngredientNameAttribute(): ?string
    {
        return $this->ingredient?->name;
    }

    public function getMenuNameAttribute(): ?string
    {
        return $this->menu?->name;
    }

    public function getItemNameAttribute(): string
    {
        if ($this->item_type === 'MENU' && $this->menu) {
            return $this->menu->name . ' (Menu)';
        }
        return $this->ingredient?->name ?? ($this->menu?->name ?? 'Terbuang');
    }

    public function getUnitPakaiAttribute(): ?string
    {
        if ($this->item_type === 'MENU' && $this->menu) {
            return $this->menu->unit || 'porsi';
        }
        return $this->ingredient?->unit_pakai;
    }

    public function getOutletNameAttribute(): ?string
    {
        return $this->outlet?->name;
    }

    public function getReporterNameAttribute(): ?string
    {
        return $this->user?->name ?? $this->creator?->name ?? 'Staf';
    }
}
