<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Role;
use App\Models\Category;
use App\Models\Unit;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
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
        // 1. Roles & Permissions Dasar
        $roles = [
            ['name' => 'Superadmin Platform', 'slug' => 'superadmin_platform', 'description' => 'Super Administrator MOVA Platform'],
            ['name' => 'Owner Bisnis',        'slug' => 'owner_bisnis',        'description' => 'Owner / Pemilik Usaha Tenant'],
            ['name' => 'Manager Outlet',      'slug' => 'manager_outlet',      'description' => 'Manager / Supervisor Outlet'],
            ['name' => 'Kasir',               'slug' => 'pegawai',             'description' => 'Staff Kasir & Front Office'],
            ['name' => 'Gudang / Kitchen',    'slug' => 'kitchen',             'description' => 'Staff Dapur & Logistik Gudang'],
        ];

        foreach ($roles as $roleData) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $roleData['slug']],
                array_merge($roleData, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        $superadminRoleId = DB::table('roles')->where('slug', 'superadmin_platform')->value('id');
        $ownerRoleId      = DB::table('roles')->where('slug', 'owner_bisnis')->value('id');

        // 2. Akun Superadmin Platform
        $superadmin = User::updateOrCreate(
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

        // 3. Setup Default Tenant (Maroa F&B Group)
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

        // 4. Setup Default Outlet (Pusat)
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

        // 5. Akun Owner Bisnis & Admin POS
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

        // 6. Master Satuan Standar (Units)
        $units = ['Kg', 'gram', 'Liter', 'ml', 'Pcs', 'Dus', 'Pack', 'Botol', 'Porsi', 'Butir', 'Lembar'];
        foreach ($units as $u) {
            DB::table('units')->updateOrInsert(
                ['name' => $u],
                ['business_id' => $business->id, 'symbol' => $u, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // 7. Master Kategori Standar
        $categories = [
            ['name' => 'Makanan Utama', 'type' => 'menu'],
            ['name' => 'Minuman',       'type' => 'menu'],
            ['name' => 'Snack / Cemilan','type' => 'menu'],
            ['name' => 'Bahan Baku',    'type' => 'ingredient'],
            ['name' => 'Bumbu Dapur',   'type' => 'ingredient'],
            ['name' => 'Packaging',     'type' => 'ingredient'],
        ];
        foreach ($categories as $cat) {
            DB::table('categories')->updateOrInsert(
                ['business_id' => $business->id, 'name' => $cat['name']],
                ['type' => $cat['type'], 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // 8. Master Kategori Biaya (Expense Categories)
        $expenseCats = [
            ['name' => 'Listrik, Air & Gas', 'description' => 'Tagihan utilitas outlet'],
            ['name' => 'Gaji & Upah Karyawan', 'description' => 'Payroll bulanan & harian'],
            ['name' => 'Sewa Tempat & Bangunan', 'description' => 'Biaya sewa outlet'],
            ['name' => 'Maintenance & Perbaikan', 'description' => 'Perawatan alat & fasilitas'],
            ['name' => 'Pemasaran & Promosi', 'description' => 'Iklan, banner & promo'],
            ['name' => 'Lain-lain / Operasional', 'description' => 'Biaya operasional lainnya'],
        ];
        foreach ($expenseCats as $ec) {
            DB::table('expense_categories')->updateOrInsert(
                ['business_id' => $business->id, 'name' => $ec['name']],
                ['description' => $ec['description'], 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // 9. Master Metode Pembayaran (Payment Methods)
        $paymentMethods = [
            ['name' => 'Cash / Tunai',        'type' => 'cash'],
            ['name' => 'QRIS (Semua E-Wallet)', 'type' => 'qris'],
            ['name' => 'Transfer Bank BCA',   'type' => 'transfer'],
            ['name' => 'Transfer Bank Mandiri','type' => 'transfer'],
            ['name' => 'Kartu Debit / EDC',   'type' => 'edc'],
            ['name' => 'Piutang Usaha / Kasbon','type' => 'receivable'],
        ];
        foreach ($paymentMethods as $pm) {
            DB::table('payment_methods')->updateOrInsert(
                ['business_id' => $business->id, 'name' => $pm['name']],
                ['type' => $pm['type'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
