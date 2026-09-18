<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, \Laravel\Sanctum\HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'approved_by',
        'approved_at',
        'outlet_id',
        'business_id',
        'referral_code',
        'referred_by_id',
    ];

    protected $appends = [
        'approved_by_name',
        'outlet_name',
        'business_name',
        'is_superadmin_platform',
        'is_owner_website',
        'is_platform_admin',
        'is_owner_bisnis',
        'is_owner_outlet',
        'is_pegawai',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approved_at'       => 'datetime',
            'password'          => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($user) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'referral_code')) {
                if (empty($user->referral_code)) {
                    $user->referral_code = static::generateUniqueReferralCode();
                }
            }
        });
    }

    public static function generateUniqueReferralCode(): string
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('users', 'referral_code')) {
            return 'REF-' . strtoupper(\Illuminate\Support\Str::random(6));
        }

        do {
            $code = 'REF-' . strtoupper(\Illuminate\Support\Str::random(6));
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by_id');
    }

    public function referredBusinesses()
    {
        return $this->hasMany(Business::class, 'referred_by_id');
    }

    public function referredUsers()
    {
        return $this->hasMany(User::class, 'referred_by_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function getApprovedByNameAttribute()
    {
        return $this->relationLoaded('approver') ? $this->approver?->name : null;
    }

    public function getOutletNameAttribute()
    {
        return $this->relationLoaded('outlet') ? $this->outlet?->name : null;
    }

    public function getBusinessIdAttribute($value)
    {
        if ($this->isPlatformAdmin()) {
            return null;
        }
        return $value;
    }

    public function getOutletIdAttribute($value)
    {
        if ($this->isPlatformAdmin()) {
            return null;
        }
        return $value;
    }

    public function getBusinessNameAttribute()
    {
        if ($this->isPlatformAdmin()) {
            return 'Platform Provider MOVA';
        }
        return $this->relationLoaded('business') ? $this->business?->name : null;
    }

    public function getIsSuperadminPlatformAttribute(): bool
    {
        return $this->isSuperadminPlatform();
    }

    public function getIsOwnerWebsiteAttribute(): bool
    {
        return $this->isOwnerWebsite();
    }

    public function getIsPlatformAdminAttribute(): bool
    {
        return $this->isPlatformAdmin();
    }

    public function getIsOwnerBisnisAttribute(): bool
    {
        return $this->isOwnerBisnis();
    }

    public function getIsOwnerOutletAttribute(): bool
    {
        return $this->isOwnerOutlet();
    }

    public function getIsPegawaiAttribute(): bool
    {
        return $this->isPegawai();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuperadminPlatform(): bool
    {
        return in_array($this->role, ['superadmin_platform', 'superadmin']);
    }

    public function isOwnerWebsite(): bool
    {
        return in_array($this->role, ['owner_website', 'superadmin_platform', 'superadmin']);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->isOwnerWebsite();
    }

    public function isOwnerBisnis(): bool
    {
        return in_array($this->role, ['owner_bisnis', 'owner', 'admin']);
    }

    public function isOwnerOutlet(): bool
    {
        return in_array($this->role, ['owner_outlet', 'manager_outlet']);
    }

    public function isPegawai(): bool
    {
        return in_array($this->role, ['pegawai', 'kasir', 'manager']);
    }

    public function canManage(User $target): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        // User dari tenant lain tidak bisa dikelola
        if ((int)$this->business_id !== (int)$target->business_id) {
            return false;
        }

        // Owner Bisnis HANYA bisa mengelola owner outlet dan pegawai di bisnisnya.
        // TIDAK BISA mengelola akun platform admin atau sesama owner_bisnis.
        if ($this->isOwnerBisnis()) {
            return !$target->isPlatformAdmin()
                && !in_array($target->role, ['owner_bisnis', 'owner', 'admin']);
        }

        // Manager outlet / Owner outlet hanya bisa mengelola pegawai di outletnya
        if ($this->isOwnerOutlet()) {
            return (int)$this->outlet_id === (int)$target->outlet_id && $target->isPegawai();
        }

        return false;
    }
}
