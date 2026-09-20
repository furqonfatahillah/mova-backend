<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutletMenu extends Model
{
    protected $table = 'outlet_menus';

    protected $fillable = [
        'outlet_id',
        'menu_id',
        'stock',
        'min_stock',
        'price',
        'cost_price',
        'active',
    ];

    protected $casts = [
        'stock'      => 'float',
        'min_stock'  => 'float',
        'price'      => 'float',
        'cost_price' => 'float',
        'active'     => 'boolean',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }
}
