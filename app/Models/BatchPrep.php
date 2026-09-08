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
}
