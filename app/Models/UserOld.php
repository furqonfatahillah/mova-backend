<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserOld extends Model
{
    use HasFactory;

    protected $table = 'users_old';

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot_data'       => 'array',
        'original_created_at' => 'datetime',
        'deleted_at'          => 'datetime',
    ];

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
