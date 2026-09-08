<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        User::create([
            'name'     => 'Admin POS',
            'email'    => 'admin@posmaroa.id',
            'password' => Hash::make('password'),
            'role'     => 'admin',
        ]);

        // Ingredients
        $ings = [
            ['code'=>'BB-001','name'=>'Ayam',    'category'=>'Protein','unit_beli'=>'Kg','unit_pakai'=>'gram','konversi'=>1000,'harga'=>35000,'stok_awal'=>20000,'stok_min'=>3000,'tolerance'=>5],
            ['code'=>'BB-002','name'=>'Tepung',  'category'=>'Kering', 'unit_beli'=>'Kg','unit_pakai'=>'gram','konversi'=>1000,'harga'=>12000,'stok_awal'=>15000,'stok_min'=>2000,'tolerance'=>5],
            ['code'=>'BB-003','name'=>'Minyak',  'category'=>'Cair',   'unit_beli'=>'Liter','unit_pakai'=>'ml','konversi'=>1000,'harga'=>18000,'stok_awal'=>12000,'stok_min'=>2000,'tolerance'=>5],
            ['code'=>'BB-004','name'=>'Sambal',  'category'=>'Bumbu',  'unit_beli'=>'Kg','unit_pakai'=>'gram','konversi'=>1000,'harga'=>20000,'stok_awal'=>8000,'stok_min'=>1000,'tolerance'=>5],
            ['code'=>'BB-005','name'=>'Beras',   'category'=>'Kering', 'unit_beli'=>'Kg','unit_pakai'=>'gram','konversi'=>1000,'harga'=>13000,'stok_awal'=>30000,'stok_min'=>5000,'tolerance'=>5],
            ['code'=>'BB-006','name'=>'Lalapan', 'category'=>'Sayur',  'unit_beli'=>'Kg','unit_pakai'=>'gram','konversi'=>1000,'harga'=>15000,'stok_awal'=>5000,'stok_min'=>1000,'tolerance'=>8],
        ];
        foreach ($ings as $d) { Ingredient::create(array_merge($d, ['active'=>true])); }

        // Menus
        $menus = [
            ['code'=>'MN-001','name'=>'Ayam Geprek', 'category'=>'Main','price'=>22000],
            ['code'=>'MN-002','name'=>'Nasi Ayam',   'category'=>'Main','price'=>25000],
            ['code'=>'MN-003','name'=>'Mie Ayam',    'category'=>'Main','price'=>18000],
        ];
        foreach ($menus as $d) { Menu::create(array_merge($d, ['active'=>true])); }

        $geprek = Menu::where('code','MN-001')->first();
        $nasiAyam = Menu::where('code','MN-002')->first();
        $mieAyam = Menu::where('code','MN-003')->first();

        $ayam = Ingredient::where('code','BB-001')->first();
        $tepung = Ingredient::where('code','BB-002')->first();
        $minyak = Ingredient::where('code','BB-003')->first();
        $sambal = Ingredient::where('code','BB-004')->first();
        $beras = Ingredient::where('code','BB-005')->first();
        $lalapan = Ingredient::where('code','BB-006')->first();

        // Recipes
        $rcpGeprek = Recipe::create(['menu_id'=>$geprek->id,'version'=>1,'date'=>'2026-07-01']);
        RecipeItem::insert([
            ['recipe_id'=>$rcpGeprek->id,'ingredient_id'=>$ayam->id,  'qty'=>100,'unit'=>'gram','waste_std'=>2,'created_at'=>now(),'updated_at'=>now()],
            ['recipe_id'=>$rcpGeprek->id,'ingredient_id'=>$tepung->id,'qty'=>30, 'unit'=>'gram','waste_std'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['recipe_id'=>$rcpGeprek->id,'ingredient_id'=>$minyak->id,'qty'=>15, 'unit'=>'ml',  'waste_std'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['recipe_id'=>$rcpGeprek->id,'ingredient_id'=>$sambal->id,'qty'=>30, 'unit'=>'gram','waste_std'=>0,'created_at'=>now(),'updated_at'=>now()],
        ]);

        $rcpNasi = Recipe::create(['menu_id'=>$nasiAyam->id,'version'=>1,'date'=>'2026-07-01']);
        RecipeItem::insert([
            ['recipe_id'=>$rcpNasi->id,'ingredient_id'=>$beras->id,  'qty'=>150,'unit'=>'gram','waste_std'=>2,'created_at'=>now(),'updated_at'=>now()],
            ['recipe_id'=>$rcpNasi->id,'ingredient_id'=>$ayam->id,   'qty'=>100,'unit'=>'gram','waste_std'=>2,'created_at'=>now(),'updated_at'=>now()],
            ['recipe_id'=>$rcpNasi->id,'ingredient_id'=>$minyak->id, 'qty'=>10, 'unit'=>'ml',  'waste_std'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['recipe_id'=>$rcpNasi->id,'ingredient_id'=>$sambal->id, 'qty'=>30, 'unit'=>'gram','waste_std'=>0,'created_at'=>now(),'updated_at'=>now()],
            ['recipe_id'=>$rcpNasi->id,'ingredient_id'=>$lalapan->id,'qty'=>20, 'unit'=>'gram','waste_std'=>1,'created_at'=>now(),'updated_at'=>now()],
        ]);

        $rcpMie = Recipe::create(['menu_id'=>$mieAyam->id,'version'=>1,'date'=>'2026-07-01']);
        RecipeItem::insert([
            ['recipe_id'=>$rcpMie->id,'ingredient_id'=>$ayam->id,  'qty'=>60,'unit'=>'gram','waste_std'=>2,'created_at'=>now(),'updated_at'=>now()],
            ['recipe_id'=>$rcpMie->id,'ingredient_id'=>$minyak->id,'qty'=>8, 'unit'=>'ml',  'waste_std'=>1,'created_at'=>now(),'updated_at'=>now()],
        ]);

        // Stock purchases Aug 2026
        $purchases = [
            ['date'=>'2026-08-05','ingredient_id'=>$ayam->id,  'qty'=>15000,'note'=>'PO-0801'],
            ['date'=>'2026-08-15','ingredient_id'=>$ayam->id,  'qty'=>15000,'note'=>'PO-0812'],
            ['date'=>'2026-08-05','ingredient_id'=>$tepung->id,'qty'=>8000, 'note'=>'PO-0801'],
            ['date'=>'2026-08-05','ingredient_id'=>$minyak->id,'qty'=>10000,'note'=>'PO-0801'],
            ['date'=>'2026-08-15','ingredient_id'=>$minyak->id,'qty'=>6000, 'note'=>'PO-0812'],
            ['date'=>'2026-08-05','ingredient_id'=>$sambal->id,'qty'=>10000,'note'=>'PO-0801'],
            ['date'=>'2026-08-05','ingredient_id'=>$beras->id, 'qty'=>20000,'note'=>'PO-0801'],
            ['date'=>'2026-08-05','ingredient_id'=>$lalapan->id,'qty'=>6000,'note'=>'PO-0801'],
        ];
        foreach ($purchases as $p) {
            StockMovement::create(array_merge($p, ['type'=>'PURCHASE']));
        }
        StockMovement::create(['date'=>'2026-08-01','ingredient_id'=>$ayam->id,'type'=>'WASTE','qty'=>300,'note'=>'Ayam basi (buang)']);

        // Seed transactions Aug 2026 (simulate POS activity)
        $menuDefs = [
            $geprek->id   => ['rcp'=>$rcpGeprek, 'base'=>9],
            $nasiAyam->id => ['rcp'=>$rcpNasi,   'base'=>6],
            $mieAyam->id  => ['rcp'=>$rcpMie,    'base'=>5],
        ];

        $trxId = 1;
        for ($d = 1; $d <= 31; $d++) {
            $date = '2026-08-' . str_pad($d, 2, '0', STR_PAD_LEFT);
            foreach ($menuDefs as $menuId => $def) {
                $jitter = round((sin($d * (strlen((string)$menuId) + 1)) + 1) * 2);
                $qty = max(1, $def['base'] + $jitter - 2);
                $menu = Menu::find($menuId);
                $trx = \App\Models\Transaction::create([
                    'date'           => $date,
                    'menu_id'        => $menuId,
                    'qty'            => $qty,
                    'recipe_version' => 1,
                    'total_price'    => $menu->price * $qty,
                ]);
                foreach ($def['rcp']->items as $item) {
                    StockMovement::create([
                        'date'           => $date,
                        'ingredient_id'  => $item->ingredient_id,
                        'type'           => 'SALE_USAGE',
                        'qty'            => $item->qty * $qty,
                        'note'           => "TRX #{$trx->id} - {$menu->name}",
                        'transaction_id' => $trx->id,
                    ]);
                }
            }
        }

        // Seed Opname August 2026
        $opnames = [
            ['ingredient_id' => $ayam->id,    'actual_qty' => 5100, 'reason' => 'Over portion', 'approver' => 'Chef Budi', 'notes' => 'Potongan ayam sedikit lebih besar pada shift malam'],
            ['ingredient_id' => $tepung->id,  'actual_qty' => 4200, 'reason' => 'Waste', 'approver' => 'Manager Siti', 'notes' => 'Sisa tepung gorengan terbuang'],
            ['ingredient_id' => $minyak->id,  'actual_qty' => 4100, 'reason' => 'Gramasi tidak sesuai', 'approver' => 'Chef Budi', 'notes' => 'Penggantian minyak lebih cepat dari jadwal'],
            ['ingredient_id' => $sambal->id,  'actual_qty' => 3900, 'reason' => 'Complimentary', 'approver' => 'Kasir Maya', 'notes' => 'Ekstra sambal untuk komplain tamu'],
            ['ingredient_id' => $beras->id,   'actual_qty' => 8200, 'reason' => 'Staff meal', 'approver' => 'Manager Siti', 'notes' => 'Makan siang karyawan'],
            ['ingredient_id' => $lalapan->id, 'actual_qty' => 2600, 'reason' => 'Produk rusak', 'approver' => 'Chef Budi', 'notes' => 'Lalapan layu di kulkas'],
        ];
        foreach ($opnames as $op) {
            \App\Models\Opname::create(array_merge($op, [
                'period_from' => '2026-08-01',
                'period_to'   => '2026-08-31',
                'is_closed'   => false,
            ]));
        }
    }
}
