<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'name',
        'label',
        'description',
        'level',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'level'     => 'integer',
    ];

    // Master Role String Constants
    public const SUPERADMIN_PLATFORM = 'superadmin_platform';
    public const OWNER_WEBSITE       = 'owner_website';
    public const OWNER_BISNIS        = 'owner_bisnis';
    public const OWNER_OUTLET        = 'owner_outlet';
    public const MANAGER_OUTLET      = 'manager_outlet';
    public const PEGAWAI             = 'pegawai';
    public const KASIR               = 'kasir';

    // Legacy Fallbacks
    public const ADMIN   = 'admin';
    public const OWNER   = 'owner';
    public const MANAGER = 'manager';

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    /**
     * Check if a role name or role_id represents Owner, Platform Admin, or Store Manager
     */
    public static function isOwnerOrManagerRole(mixed $roleIdOrName): bool
    {
        if (empty($roleIdOrName)) {
            return false;
        }

        if (is_numeric($roleIdOrName)) {
            $role = static::find($roleIdOrName);
            $name = $role?->name;
        } else {
            $name = strtolower(trim((string)$roleIdOrName));
        }

        return in_array($name, [
            self::OWNER_BISNIS,
            self::OWNER,
            self::ADMIN,
            self::OWNER_WEBSITE,
            self::SUPERADMIN_PLATFORM,
            'superadmin',
            self::OWNER_OUTLET,
            self::MANAGER_OUTLET,
            self::MANAGER,
        ]);
    }

    /**
     * Check if a role is SaaS Platform Provider (Superadmin / Owner Website)
     */
    public static function isPlatformRole(mixed $roleIdOrName): bool
    {
        if (empty($roleIdOrName)) {
            return false;
        }

        if (is_numeric($roleIdOrName)) {
            $role = static::find($roleIdOrName);
            $name = $role?->name;
        } else {
            $name = strtolower(trim((string)$roleIdOrName));
        }

        return in_array($name, [
            self::SUPERADMIN_PLATFORM,
            self::OWNER_WEBSITE,
            'superadmin',
        ]);
    }

    /**
     * Check if a role is Tenant Business Owner
     */
    public static function isBusinessOwnerRole(mixed $roleIdOrName): bool
    {
        if (empty($roleIdOrName)) {
            return false;
        }

        if (is_numeric($roleIdOrName)) {
            $role = static::find($roleIdOrName);
            $name = $role?->name;
        } else {
            $name = strtolower(trim((string)$roleIdOrName));
        }

        return in_array($name, [
            self::OWNER_BISNIS,
            self::OWNER,
            self::ADMIN,
        ]);
    }

    /**
     * Check if a role is Branch/Outlet level
     */
    public static function isOutletRole(mixed $roleIdOrName): bool
    {
        if (empty($roleIdOrName)) {
            return false;
        }

        if (is_numeric($roleIdOrName)) {
            $role = static::find($roleIdOrName);
            $name = $role?->name;
        } else {
            $name = strtolower(trim((string)$roleIdOrName));
        }

        return in_array($name, [
            self::OWNER_OUTLET,
            self::MANAGER_OUTLET,
            self::MANAGER,
            self::PEGAWAI,
            self::KASIR,
        ]);
    }
}
