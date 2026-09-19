<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Ambil Role ID dari tabel roles (yang sudah di-generate oleh migration)
        $superadminRoleId = DB::table('roles')->where('name', 'superadmin_platform')->value('id');
        $ownerRoleId      = DB::table('roles')->where('name', 'owner_bisnis')->value('id');

        // 2. Setup Akun Superadmin Platform (Penyedia Software MOVA)
        User::updateOrCreate(
            ['email' => 'superadmin@mova.id'],
            [
                'name'        => 'Superadmin Platform MOVA',
                'password'    => Hash::make('password'),
                'role'        => 'superadmin_platform',
                'role_id'     => $superadminRoleId,
                'status'      => 'active',
                'business_id' => null,
                'outlet_id'   => null,
                'approved_at' => now(),
            ]
        );

        // 3. Setup Default Tenant Usaha (Maroa F&B Group)
        $business = Business::firstOrCreate(
            ['slug' => 'maroa-fb-group'],
            [
                'name'         => 'Maroa F&B Group',
                'owner_name'   => 'Owner Maroa',
                'email'        => 'owner@mova.id',
                'phone'        => '0812-3456-7890',
                'address'      => 'Makassar, Sulawesi Selatan',
                'package_type' => 'enterprise',
                'max_outlets'  => 10,
                'status'       => 'active',
                'expires_at'   => now()->addYears(5),
            ]
        );

        // 4. Setup Default Outlet Cabang Utama
        $outlet = Outlet::withoutGlobalScopes()->firstOrCreate(
            ['business_id' => $business->id, 'code' => 'OUT-001'],
            [
                'name'       => 'Maroa - Cabang Utama (Pusat)',
                'address'    => 'Makassar',
                'phone'      => '0812-3456-7890',
                'pic_name'   => 'Owner Maroa',
                'is_main'    => true,
                'active'     => true,
            ]
        );

        // 5. Setup Akun Owner Bisnis & Admin POS
        User::updateOrCreate(
            ['email' => 'owner@mova.id'],
            [
                'name'        => 'Owner Maroa F&B',
                'password'    => Hash::make('password'),
                'role'        => 'owner_bisnis',
                'role_id'     => $ownerRoleId,
                'status'      => 'active',
                'business_id' => $business->id,
                'outlet_id'   => $outlet->id,
                'approved_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@posmaroa.id'],
            [
                'name'        => 'Admin POS Maroa',
                'password'    => Hash::make('password'),
                'role'        => 'owner_bisnis',
                'role_id'     => $ownerRoleId,
                'status'      => 'active',
                'business_id' => $business->id,
                'outlet_id'   => $outlet->id,
                'approved_at' => now(),
            ]
        );
    }
}
