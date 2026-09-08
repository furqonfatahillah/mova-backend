<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class ModifierGroup extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'selection_type',
        'is_required',
        'min_selection',
        'max_selection',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_required'   => 'boolean',
        'min_selection' => 'integer',
        'max_selection' => 'integer',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
        'options_count',
        'menus_count',
    ];

    public function options()
    {
        return $this->hasMany(ModifierOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function menus()
    {
        return $this->belongsToMany(Menu::class, 'menu_modifier_groups')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function getOptionsCountAttribute(): int
    {
        return $this->options()->count();
    }

    public function getMenusCountAttribute(): int
    {
        return $this->menus()->count();
    }
}
