<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class BatchPrep extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'outlet_id',
        'batch_no',
        'prep_recipe_id',
        'ingredient_id',
        'batch_multiplier',
        'expected_output_qty',
        'actual_output_qty',
        'output_unit',
        'total_cost',
        'unit_cost',
        'date',
        'notes',
        'status',
        'user_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'batch_multiplier'    => 'float',
        'expected_output_qty' => 'float',
        'actual_output_qty'   => 'float',
        'total_cost'          => 'float',
        'unit_cost'           => 'float',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
        'user_name',
        'outlet_name',
        'yield_variance_qty',
        'yield_variance_pct',
        'has_variance',
        'variance_status',
        'finding_summary',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function prepRecipe()
    {
        return $this->belongsTo(PrepRecipe::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function getUserNameAttribute(): ?string
    {
        return $this->user?->name;
    }

    public function getOutletNameAttribute(): ?string
    {
        return $this->outlet?->name;
    }

    public function getYieldVarianceQtyAttribute(): float
    {
        return round((float)$this->actual_output_qty - (float)$this->expected_output_qty, 3);
    }

    public function getYieldVariancePctAttribute(): float
    {
        $expected = (float)$this->expected_output_qty;
        if ($expected <= 0) return 0.0;
        return round((((float)$this->actual_output_qty - $expected) / $expected) * 100, 2);
    }

    public function getHasVarianceAttribute(): bool
    {
        return abs((float)$this->actual_output_qty - (float)$this->expected_output_qty) > 0.001;
    }

    public function getVarianceStatusAttribute(): string
    {
        $diff = round((float)$this->actual_output_qty - (float)$this->expected_output_qty, 3);
        if (abs($diff) <= 0.001) {
            return 'NORMAL';
        }
        return $diff < 0 ? 'DEFICIT' : 'SURPLUS';
    }

    public function getFindingSummaryAttribute(): string
    {
        $diff = $this->yield_variance_qty;
        $pct = $this->yield_variance_pct;
        $unit = $this->output_unit;
        if (abs($diff) <= 0.001) {
            return "Hasil produksi presisi sesuai standar resep ({$this->actual_output_qty} {$unit}).";
        }
        if ($diff < 0) {
            return "Temuan Defisit Produksi: Hasil fisik riil ({$this->actual_output_qty} {$unit}) lebih sedikit " . abs($diff) . " {$unit} (" . abs($pct) . "%) dari target resep ({$this->expected_output_qty} {$unit}).";
        }
        return "Temuan Surplus Produksi: Hasil fisik riil ({$this->actual_output_qty} {$unit}) lebih banyak +{$diff} {$unit} (+{$pct}%) dari target resep ({$this->expected_output_qty} {$unit}).";
    }
}
