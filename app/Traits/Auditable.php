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

    protected static array $userNameCache = [];

    /**
     * Display name of creator.
     */
    public function getCreatedByNameAttribute(): ?string
    {
        if ($this->relationLoaded('creator') && $this->creator) {
            return $this->creator->name;
        }
        if ($this->relationLoaded('user') && $this->user) {
            return $this->user->name;
        }
        if (!empty($this->created_by)) {
            if (!array_key_exists($this->created_by, static::$userNameCache)) {
                static::$userNameCache[$this->created_by] = User::find($this->created_by)?->name;
            }
            return static::$userNameCache[$this->created_by];
        }
        if (!empty($this->user_id)) {
            if (!array_key_exists($this->user_id, static::$userNameCache)) {
                static::$userNameCache[$this->user_id] = User::find($this->user_id)?->name;
            }
            return static::$userNameCache[$this->user_id];
        }
        return null;
    }

    /**
     * Display name of updater (if changed).
     */
    public function getUpdatedByNameAttribute(): ?string
    {
        if ($this->relationLoaded('updater') && $this->updater) {
            return $this->updater->name;
        }
        if ($this->relationLoaded('closedByUser') && $this->closedByUser) {
            return $this->closedByUser->name;
        }
        if (!empty($this->updated_by)) {
            if (!array_key_exists($this->updated_by, static::$userNameCache)) {
                static::$userNameCache[$this->updated_by] = User::find($this->updated_by)?->name;
            }
            return static::$userNameCache[$this->updated_by];
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
