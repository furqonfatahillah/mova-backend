<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;

class ShiftSchedule extends Model
{
    use HasFactory, Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'outlet_id',
        'shift_name',
        'start_time',
        'end_time',
        'assigned_user_ids',
        'is_strict',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'assigned_user_ids' => 'array',
        'is_strict'         => 'boolean',
        'active'            => 'boolean',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
        'changed_at',
        'changed_by_name',
        'assigned_users',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function getAssignedUsersAttribute()
    {
        $ids = $this->assigned_user_ids;
        if (empty($ids) || !is_array($ids)) {
            return [];
        }

        // Strictly scoped to the same company (business_id)
        return User::whereIn('id', $ids)
            ->where('business_id', $this->business_id)
            ->select('id', 'name', 'email', 'role', 'outlet_id')
            ->get();
    }
}
