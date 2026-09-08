<?php

namespace App\Traits;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        // Global scope per tenant
        static::addGlobalScope('business', function (Builder $builder) {
            static $inScope = false;
            if ($inScope) return;
            $inScope = true;

            try {
                $user = (app()->bound('request') ? request()->user() : null) ?? auth('sanctum')->user() ?? auth()->user();

                if ($user) {
                    // Jika superadmin platform, bisa melihat semua atau difilter jika ada header X-Business-Id
                    if ($user->isSuperadminPlatform()) {
                        $businessId = request()->header('X-Business-Id') ?? request()->query('business_id');
                        if ($businessId && is_numeric($businessId)) {
                            $builder->where($builder->getModel()->getTable() . '.business_id', (int)$businessId);
                        }
                        return;
                    }

                    // Untuk tenant user (owner_bisnis, owner_outlet, pegawai), kunci ke business_id milik mereka
                    if ($user->business_id) {
                        $builder->where($builder->getModel()->getTable() . '.business_id', $user->business_id);
                    }
                }
            } finally {
                $inScope = false;
            }
        });

        // Auto-assign business_id saat pembuatan data baru
        static::creating(function ($model) {
            if (empty($model->business_id)) {
                $user = auth('sanctum')->user() ?? auth()->user() ?? (app()->bound('request') ? request()->user() : null);
                if ($user) {
                    if ($user->business_id) {
                        $model->business_id = $user->business_id;
                    } elseif ($user->isSuperadminPlatform()) {
                        $businessId = request()->header('X-Business-Id') ?? request()->query('business_id') ?? request()->input('business_id');
                        if ($businessId) {
                            $model->business_id = (int)$businessId;
                        }
                    }
                }
            }
        });
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($this->getTable() . '.business_id', $businessId);
    }
}
