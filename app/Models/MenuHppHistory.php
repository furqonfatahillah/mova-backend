<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuHppHistory extends Model
{
    protected $table = 'menu_hpp_histories';

    protected $fillable = [
        'business_id',
        'menu_id',
        'outlet_id',
        'date',
        'hpp_before',
        'hpp_after',
        'diff',
        'percentage_change',
        'selling_price',
        'margin_before_pct',
        'margin_after_pct',
        'trigger_type',
        'ingredient_id',
        'ingredient_name',
        'ingredient_cost_before',
        'ingredient_cost_after',
        'portion_qty',
        'portion_unit',
        'portion_cost_impact',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'date'                   => 'date:Y-m-d',
        'hpp_before'             => 'float',
        'hpp_after'              => 'float',
        'diff'                   => 'float',
        'percentage_change'      => 'float',
        'selling_price'          => 'float',
        'margin_before_pct'      => 'float',
        'margin_after_pct'       => 'float',
        'ingredient_cost_before' => 'float',
        'ingredient_cost_after'  => 'float',
        'portion_qty'            => 'float',
        'portion_cost_impact'    => 'float',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
