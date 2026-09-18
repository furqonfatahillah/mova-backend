<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToBusiness;

class Category extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'type', // MENU, INGREDIENT, GENERAL
        'color',
        'icon',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function menus()
    {
        return $this->hasMany(Menu::class);
    }

    public function ingredients()
    {
        return $this->hasMany(Ingredient::class);
    }
}
