<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class Menu extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = ['business_id', 'code', 'name', 'category', 'price', 'active', 'created_by', 'updated_by'];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    protected $casts = ['price' => 'float', 'active' => 'boolean'];

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function modifierGroups()
    {
        return $this->belongsToMany(ModifierGroup::class, 'menu_modifier_groups')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->with(['options.ingredient']);
    }

    /** Active recipe at or before a given date */
    public function activeRecipe(?string $atDate = null): ?Recipe
    {
        return $this->recipes()
            ->when($atDate, fn($q) => $q->where('date', '<=', $atDate))
            ->orderByDesc('version')
            ->first();
    }
}
