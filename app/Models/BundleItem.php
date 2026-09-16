<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BundleItem extends Model
{
    protected $fillable = [
        'menu_id',
        'bundled_menu_id',
        'ingredient_id',
        'qty',
        'unit',
    ];

    protected $casts = [
        'qty' => 'float',
    ];

    public function parentMenu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    public function bundledMenu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'bundled_menu_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'ingredient_id');
    }
}
