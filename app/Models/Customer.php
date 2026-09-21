<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use Auditable, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'code',
        'name',
        'phone',
        'email',
        'address',
        'birth_date',
        'total_points',
        'total_visits',
        'total_spent',
        'joined_at',
        'active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'total_points' => 'integer',
        'total_visits' => 'integer',
        'total_spent'  => 'float',
        'active'       => 'boolean',
        'birth_date'   => 'date:Y-m-d',
        'joined_at'    => 'date:Y-m-d',
    ];

    protected $appends = [
        'created_by_name',
        'updated_by_name',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function pointRedemptions(): HasMany
    {
        return $this->hasMany(PointRedemption::class)->orderByDesc('id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Add points to customer balance.
     */
    public function addPoints(int $points = 1): self
    {
        if ($points > 0) {
            $this->increment('total_points', $points);
        }
        return $this;
    }

    /**
     * Use / redeem customer points.
     */
    public function usePoints(int $points): bool
    {
        if ($points <= 0) return true;
        if ($this->total_points < $points) return false;

        $this->decrement('total_points', $points);
        return true;
    }

    /**
     * Check if customer has enough points.
     */
    public function hasEnoughPoints(int $needed): bool
    {
        if ($needed <= 0) return true;
        return $this->total_points >= $needed;
    }

    /**
     * Record a completed visit (PAID transaction).
     */
    public function recordVisit(float $spentAmount, int $pointsToAdd = 1): self
    {
        $this->increment('total_visits', 1);
        if ($spentAmount > 0) {
            $this->increment('total_spent', $spentAmount);
        }
        if ($pointsToAdd > 0) {
            $this->increment('total_points', $pointsToAdd);
        }
        return $this;
    }

    /**
     * Generate unique customer code for a business: e.g. MBR-0001
     */
    public static function generateCode(int $businessId): string
    {
        $lastId = static::where('business_id', $businessId)->max('id') ?? 0;
        $nextSeq = $lastId + 1;
        return sprintf("MBR-%04d", $nextSeq);
    }
}
