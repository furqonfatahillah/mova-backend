<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToBusiness;

class Supplier extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'phone',
        'contact_person',
        'address',
        'notes',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function payables()
    {
        return $this->hasMany(Payable::class);
    }
}
