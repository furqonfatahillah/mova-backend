<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Recipe extends Model
{
    use Auditable;

    protected $fillable = ['menu_id', 'version', 'date', 'created_by', 'updated_by'];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function items()
    {
        return $this->hasMany(RecipeItem::class);
    }
}
