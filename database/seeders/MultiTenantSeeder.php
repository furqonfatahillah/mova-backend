<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Outlet;
use App\Models\OutletIngredient;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MultiTenantSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Akun Superadmin Platform (Penyedia Software MOVA POS)
        User::updateOrCreate(
            ['email' => 'superadmin@mova.id'],
            [
                'name'        => 'Superadmin Platform MOVA',
                'password'    => Hash::make('password'),
                'role'        => 'superadmin_platform',
                'status'      => 'active',
                'business_id' => null, // Superadmin tidak terikat satu tenant
                'outlet_id'   => null,
                'approved_at' => now(),
            ]
        );

        // 2. Pastikan Tenant 1: Maroa F&B Group
        $maroa = Business::firstOrCreate(
            ['slug' => 'maroa-fb-group'],
            [
                'name'         => 'Maroa F&B Group',
                'owner_name'   => 'Owner Maroa',
                'email'        => 'owner@mova.id',
                'phone'        => '0812-3456-7890',
                'address'      => 'Jl. Boulevard No. 1, Panakkukang, Makassar',
                'package_type' => 'enterprise',
                'max_outlets'  => 10,
                'status'       => 'active',
                'expires_at'   => now()->addYears(5),
            ]
        );

        // Pastikan owner@mova.id & admin@posmaroa.id terikat ke Maroa
        User::updateOrCreate(
            ['email' => 'owner@mova.id'],
            [
                'name'        => 'Owner Maroa F&B Group',
                'password'    => Hash::make('password'),
                'role'        => 'owner_bisnis',
                'status'      => 'active',
                'business_id' => $maroa->id,
                'outlet_id'   => 1,
                'approved_at' => now(),
            ]
        );

        $adminPos = User::where('email', 'admin@posmaroa.id')->first();
        if ($adminPos) {
            $adminPos->update([
                'business_id' => $maroa->id,
                'role'        => 'owner_bisnis',
                'status'      => 'active',
            ]);
        }

        // 3. Buat Tenant 2: Kopi Kenangan Senja (Penyewa Baru)
        $senja = Business::updateOrCreate(
            ['slug' => 'kopi-kenangan-senja'],
            [
                'name'         => 'Kopi Kenangan Senja',
                'owner_name'   => 'Ibu Sari Anggraini',
                'email'        => 'owner.kopi@senja.id',
                'phone'        => '0812-9988-7766',
                'address'      => 'Jl. Ranggong No. 18, Ujung Pandang, Makassar',
                'package_type' => 'pro',
                'max_outlets'  => 5,
                'status'       => 'active',
                'expires_at'   => now()->addYear(),
            ]
        );

        // Cabang-cabang Kopi Senja
        $cabRanggong = Outlet::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $senja->id, 'code' => 'OUT-001'],
            [
                'name'       => 'Kopi Senja - Cabang Ranggong (Pusat)',
                'address'    => 'Jl. Ranggong No. 18, Makassar',
                'phone'      => '0812-9988-7766',
                'pic_name'   => 'Ibu Sari Anggraini',
                'is_main'    => true,
                'active'     => true,
            ]
        );

        $cabBoulevard = Outlet::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $senja->id, 'code' => 'OUT-002'],
            [
                'name'       => 'Kopi Senja - Cabang Boulevard (Express)',
                'address'    => 'Jl. Boulevard Blok F No. 8, Panakkukang, Makassar',
                'phone'      => '0812-1122-3344',
                'pic_name'   => 'Rian (Store Lead)',
                'is_main'    => false,
                'active'     => true,
            ]
        );

        // User Owner Kopi Senja
        $senjaOwner = User::updateOrCreate(
            ['email' => 'owner.kopi@senja.id'],
            [
                'name'        => 'Ibu Sari Anggraini (Owner Kopi Senja)',
                'password'    => Hash::make('password'),
                'role'        => 'owner_bisnis',
                'status'      => 'active',
                'business_id' => $senja->id,
                'outlet_id'   => $cabRanggong->id,
                'approved_at' => now(),
            ]
        );

        // Kasir Kopi Senja
        User::updateOrCreate(
            ['email' => 'kasir.kopi@senja.id'],
            [
                'name'        => 'Fani (Kasir Kopi Senja)',
                'password'    => Hash::make('password'),
                'role'        => 'pegawai',
                'status'      => 'active',
                'business_id' => $senja->id,
                'outlet_id'   => $cabRanggong->id,
                'approved_by' => $senjaOwner->id,
                'approved_at' => now(),
            ]
        );

        // Bahan Baku Kopi Senja (HANYA MILIK KOPI SENJA)
        $bijiKopi = Ingredient::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $senja->id, 'code' => 'KS-BB01'],
            [
                'name'       => 'Biji Kopi Arabika Gayo',
                'category'   => 'Biji Kopi',
                'unit_beli'  => 'Kg',
                'unit_pakai' => 'gram',
                'konversi'   => 1000,
                'harga'      => 180000,
                'stok_awal'  => 8000,
                'stok_min'   => 1500,
                'tolerance'  => 5,
                'active'     => true,
            ]
        );

        $susuUHT = Ingredient::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $senja->id, 'code' => 'KS-BB02'],
            [
                'name'       => 'Susu Segar UHT Full Cream',
                'category'   => 'Dairy',
                'unit_beli'  => 'Liter',
                'unit_pakai' => 'ml',
                'konversi'   => 1000,
                'harga'      => 20000,
                'stok_awal'  => 15000,
                'stok_min'   => 2000,
                'tolerance'  => 5,
                'active'     => true,
            ]
        );

        $gulaAren = Ingredient::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $senja->id, 'code' => 'KS-BB03'],
            [
                'name'       => 'Sirup Gula Aren Organik',
                'category'   => 'Sirup & Manisan',
                'unit_beli'  => 'Liter',
                'unit_pakai' => 'ml',
                'konversi'   => 1000,
                'harga'      => 35000,
                'stok_awal'  => 6000,
                'stok_min'   => 1000,
                'tolerance'  => 5,
                'active'     => true,
            ]
        );

        // Set stok awal per outlet untuk Kopi Senja
        foreach ([$bijiKopi, $susuUHT, $gulaAren] as $ing) {
            OutletIngredient::updateOrCreate(
                ['outlet_id' => $cabRanggong->id, 'ingredient_id' => $ing->id],
                ['stok_awal' => $ing->stok_awal, 'stok_min' => $ing->stok_min]
            );
            OutletIngredient::updateOrCreate(
                ['outlet_id' => $cabBoulevard->id, 'ingredient_id' => $ing->id],
                ['stok_awal' => 0, 'stok_min' => $ing->stok_min]
            );
        }

        // Menu & Resep Kopi Senja (HANYA MILIK KOPI SENJA)
        $menuKopiSusu = Menu::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $senja->id, 'code' => 'KS-MN01'],
            [
                'name'     => 'Kopi Susu Gula Aren (Signature)',
                'category' => 'Coffee',
                'price'    => 24000,
                'active'   => true,
            ]
        );

        $rcpKopiSusu = Recipe::firstOrCreate(
            ['menu_id' => $menuKopiSusu->id, 'version' => 1],
            ['date' => now()->toDateString()]
        );
        RecipeItem::where('recipe_id', $rcpKopiSusu->id)->delete();
        RecipeItem::insert([
            ['recipe_id' => $rcpKopiSusu->id, 'ingredient_id' => $bijiKopi->id, 'qty' => 18, 'unit' => 'gram', 'waste_std' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['recipe_id' => $rcpKopiSusu->id, 'ingredient_id' => $susuUHT->id, 'qty' => 120, 'unit' => 'ml', 'waste_std' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['recipe_id' => $rcpKopiSusu->id, 'ingredient_id' => $gulaAren->id, 'qty' => 25, 'unit' => 'ml', 'waste_std' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $menuEspresso = Menu::withoutGlobalScopes()->updateOrCreate(
            ['business_id' => $senja->id, 'code' => 'KS-MN02'],
            [
                'name'     => 'Double Shot Espresso',
                'category' => 'Coffee',
                'price'    => 18000,
                'active'   => true,
            ]
        );

        $rcpEspresso = Recipe::firstOrCreate(
            ['menu_id' => $menuEspresso->id, 'version' => 1],
            ['date' => now()->toDateString()]
        );
        RecipeItem::where('recipe_id', $rcpEspresso->id)->delete();
        RecipeItem::insert([
            ['recipe_id' => $rcpEspresso->id, 'ingredient_id' => $bijiKopi->id, 'qty' => 18, 'unit' => 'gram', 'waste_std' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
