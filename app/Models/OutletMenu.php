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
        'price_online',
        'price_grab',
        'price_gofood',
        'price_shopeefood',
        'cost_price',
        'active',
    ];

    protected $casts = [
        'stock'            => 'float',
        'min_stock'        => 'float',
        'price'            => 'float',
        'price_online'     => 'float',
        'price_grab'       => 'float',
        'price_gofood'     => 'float',
        'price_shopeefood' => 'float',
        'cost_price'       => 'float',
        'active'           => 'boolean',
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
