<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                if (empty($model->created_by)) {
                    $model->created_by = Auth::id();
                }
                if (array_key_exists('user_id', $model->getAttributes()) && empty($model->user_id)) {
                    $model->user_id = Auth::id();
                }
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }

    /**
     * User who created this record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last changed/updated this record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Display name of creator.
     */
    public function getCreatedByNameAttribute(): ?string
    {
        if ($this->creator) {
            return $this->creator->name;
        }
        if ($this->relationLoaded('user') && $this->user) {
            return $this->user->name;
        }
        return null;
    }

    /**
     * Display name of updater (if changed).
     */
    public function getUpdatedByNameAttribute(): ?string
    {
        if ($this->updater) {
            return $this->updater->name;
        }
        if ($this->relationLoaded('closedByUser') && $this->closedByUser) {
            return $this->closedByUser->name;
        }
        return null;
    }

    /**
     * Alias for changed_at
     */
    public function getChangedAtAttribute()
    {
        return $this->updated_at;
    }

    /**
     * Alias for changed_by
     */
    public function getChangedByAttribute()
    {
        return $this->updated_by;
    }

    /**
     * Alias for changed_by_name
     */
    public function getChangedByNameAttribute(): ?string
    {
        return $this->updated_by_name;
    }
}
