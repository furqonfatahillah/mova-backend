<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToBusiness;

class Unit extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'symbol',
        'is_base_unit',
    ];

    protected $casts = [
        'is_base_unit' => 'boolean',
    ];

    public function ingredientsAsBeli()
    {
        return $this->hasMany(Ingredient::class, 'unit_beli_id');
    }

    public function ingredientsAsPakai()
    {
        return $this->hasMany(Ingredient::class, 'unit_pakai_id');
    }
}
