<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Outlet;
use App\Models\Business;
use App\Models\Ingredient;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class TransferStockSufficiencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_transfer_rejected_when_ingredient_stock_is_insufficient()
    {
        $business = Business::first() ?? Business::create([
            'name' => 'Test Business',
            'code' => 'TB01',
        ]);

        $outletA = Outlet::create([
            'business_id' => $business->id,
            'name'        => 'Cabang Asal Test',
            'code'        => 'CAT_' . uniqid(),
            'address'     => 'Jl Asal',
        ]);

        $outletB = Outlet::create([
            'business_id' => $business->id,
            'name'        => 'Cabang Tujuan Test',
            'code'        => 'CBT_' . uniqid(),
            'address'     => 'Jl Tujuan',
        ]);

        $admin = User::create([
            'business_id' => $business->id,
            'outlet_id'   => $outletA->id,
            'name'        => 'Admin Test',
            'email'       => 'admin_' . uniqid() . '@test.com',
            'password'    => bcrypt('password'),
            'role'        => 'OWNER',
        ]);

        $ingredient = Ingredient::create([
            'business_id' => $business->id,
            'code'        => 'ING-' . uniqid(),
            'name'        => 'Bahan Test Stock',
            'unit_pakai'  => 'gram',
            'unit_beli'   => 'kg',
            'konversi'    => 1000,
            'harga'       => 50000,
            'stok_awal'   => 0,
        ]);

        // Beri stok 500 gram di Cabang Asal
        StockMovement::create([
            'business_id'   => $business->id,
            'date'          => date('Y-m-d'),
            'ingredient_id' => $ingredient->id,
            'outlet_id'     => $outletA->id,
            'type'          => 'PURCHASE',
            'qty'           => 500,
            'unit_price'    => 50000,
            'total_price'   => 25000,
        ]);

        // Coba transfer 1000 gram (melebihi stok 500 gram)
        $response = $this->actingAs($admin)->postJson('/api/transfers', [
            'date'                  => date('Y-m-d'),
            'source_outlet_id'      => $outletA->id,
            'destination_outlet_id' => $outletB->id,
            'items' => [
                [
                    'item_type'     => 'INGREDIENT',
                    'ingredient_id' => $ingredient->id,
                    'input_qty'     => 1000,
                    'input_unit'    => 'gram',
                    'qty'           => 1000,
                    'unit'          => 'gram',
                ]
            ]
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Transfer ditolak: Stok bahan atau produk di cabang asal tidak mencukupi.'
        ]);

        // Coba transfer 1 kg (= 1000 gram, via unit_beli)
        $responseUnitBeli = $this->actingAs($admin)->postJson('/api/transfers', [
            'date'                  => date('Y-m-d'),
            'source_outlet_id'      => $outletA->id,
            'destination_outlet_id' => $outletB->id,
            'items' => [
                [
                    'item_type'     => 'INGREDIENT',
                    'ingredient_id' => $ingredient->id,
                    'input_qty'     => 1,
                    'input_unit'    => 'kg',
                    'qty'           => 1,
                    'unit'          => 'gram',
                ]
            ]
        ]);

        $responseUnitBeli->assertStatus(422);

        // Coba transfer 2 baris masing-masing 300 gram (total 600 gram, melebihi stok 500 gram)
        $responseMultiRowDeficit = $this->actingAs($admin)->postJson('/api/transfers', [
            'date'                  => date('Y-m-d'),
            'source_outlet_id'      => $outletA->id,
            'destination_outlet_id' => $outletB->id,
            'items' => [
                [
                    'item_type'     => 'INGREDIENT',
                    'ingredient_id' => $ingredient->id,
                    'input_qty'     => 300,
                    'input_unit'    => 'gram',
                    'qty'           => 300,
                    'unit'          => 'gram',
                ],
                [
                    'item_type'     => 'INGREDIENT',
                    'ingredient_id' => $ingredient->id,
                    'input_qty'     => 300,
                    'input_unit'    => 'gram',
                    'qty'           => 300,
                    'unit'          => 'gram',
                ]
            ]
        ]);
        $responseMultiRowDeficit->assertStatus(422);

        // Coba transfer 400 gram (cukup, stok 500 gram)
        $responseSuccess = $this->actingAs($admin)->postJson('/api/transfers', [
            'date'                  => date('Y-m-d'),
            'source_outlet_id'      => $outletA->id,
            'destination_outlet_id' => $outletB->id,
            'items' => [
                [
                    'item_type'     => 'INGREDIENT',
                    'ingredient_id' => $ingredient->id,
                    'input_qty'     => 400,
                    'input_unit'    => 'gram',
                    'qty'           => 400,
                    'unit'          => 'gram',
                ]
            ]
        ]);

        $responseSuccess->assertStatus(201);
    }

    public function test_transfer_rejected_when_product_stock_is_insufficient()
    {
        $business = Business::first() ?? Business::create([
            'name' => 'Test Business',
            'code' => 'TB01',
        ]);

        $outletA = Outlet::create([
            'business_id' => $business->id,
            'name'        => 'Cabang Asal Product Test',
            'code'        => 'CPAT_' . uniqid(),
            'address'     => 'Jl Asal',
        ]);

        $outletB = Outlet::create([
            'business_id' => $business->id,
            'name'        => 'Cabang Tujuan Product Test',
            'code'        => 'CPBT_' . uniqid(),
            'address'     => 'Jl Tujuan',
        ]);

        $admin = User::create([
            'business_id' => $business->id,
            'outlet_id'   => $outletA->id,
            'name'        => 'Admin Product Test',
            'email'       => 'admin_prd_' . uniqid() . '@test.com',
            'password'    => bcrypt('password'),
            'role'        => 'OWNER',
        ]);

        $menu = \App\Models\Menu::create([
            'business_id' => $business->id,
            'code'        => 'PRD-' . uniqid(),
            'name'        => 'Produk Retail Test',
            'item_type'   => 'DIRECT',
            'track_stock' => true,
            'stock'       => 0,
            'price'       => 15000,
            'cost_price'  => 10000,
            'unit'        => 'pcs',
        ]);

        \App\Models\OutletMenu::create([
            'outlet_id' => $outletA->id,
            'menu_id'   => $menu->id,
            'stock'     => 3,
            'price'     => 15000,
        ]);

        // Coba transfer 5 pcs (melebihi stok 3 pcs)
        $response = $this->actingAs($admin)->postJson('/api/transfers', [
            'date'                  => date('Y-m-d'),
            'source_outlet_id'      => $outletA->id,
            'destination_outlet_id' => $outletB->id,
            'items' => [
                [
                    'item_type' => 'PRODUCT',
                    'menu_id'   => $menu->id,
                    'input_qty' => 5,
                    'input_unit'=> 'pcs',
                    'qty'       => 5,
                    'unit'      => 'pcs',
                ]
            ]
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Transfer ditolak: Stok bahan atau produk di cabang asal tidak mencukupi.'
        ]);

        // Coba transfer 2 pcs (cukup, stok 3 pcs)
        $responseSuccess = $this->actingAs($admin)->postJson('/api/transfers', [
            'date'                  => date('Y-m-d'),
            'source_outlet_id'      => $outletA->id,
            'destination_outlet_id' => $outletB->id,
            'items' => [
                [
                    'item_type' => 'PRODUCT',
                    'menu_id'   => $menu->id,
                    'input_qty' => 2,
                    'input_unit'=> 'pcs',
                    'qty'       => 2,
                    'unit'      => 'pcs',
                ]
            ]
        ]);

        $responseSuccess->assertStatus(201);
    }

    public function test_in_transit_purchase_from_shopee_and_approval_receive()
    {
        $business = Business::first() ?? Business::create([
            'name' => 'Test Business',
            'code' => 'TB01',
        ]);

        $outlet = Outlet::create([
            'business_id' => $business->id,
            'name'        => 'Cabang Penerima Shopee',
            'code'        => 'CPS_' . uniqid(),
            'address'     => 'Jl Penerima',
        ]);

        $admin = User::create([
            'business_id' => $business->id,
            'outlet_id'   => $outlet->id,
            'name'        => 'Admin Shopee Test',
            'email'       => 'admin_shopee_' . uniqid() . '@test.com',
            'password'    => bcrypt('password'),
            'role'        => 'OWNER',
        ]);

        $ingredient = Ingredient::create([
            'business_id' => $business->id,
            'code'        => 'ING-' . uniqid(),
            'name'        => 'Tepung Terigu Shopee',
            'unit_pakai'  => 'gram',
            'unit_beli'   => 'kg',
            'konversi'    => 1000,
            'harga'       => 20000,
            'stok_awal'   => 0,
        ]);

        // 1. Simpan Pembelian Online via Shopee (Persediaan Dalam Perjalanan)
        $shopeePricePerKg = 25000;
        $shopeeQtyKg = 10; // 10 kg = 10,000 gram
        $totalShopeePrice = $shopeePricePerKg * $shopeeQtyKg;

        $responseCreate = $this->actingAs($admin)->postJson('/api/transfers', [
            'date'                  => date('Y-m-d'),
            'source_type'           => 'EXTERNAL',
            'source_name'           => 'Shopee (Toko Bahan Kue ABC)',
            'destination_type'      => 'OUTLET',
            'destination_outlet_id' => $outlet->id,
            'transfer_type'         => 'INBOUND',
            'status'                => 'IN_TRANSIT',
            'driver_name'           => 'Shopee Xpress',
            'vehicle_no'            => 'SPXID99887766',
            'notes'                 => 'Pembelian bahan online lewat Shopee',
            'items' => [
                [
                    'item_type'     => 'INGREDIENT',
                    'ingredient_id' => $ingredient->id,
                    'input_qty'     => $shopeeQtyKg,
                    'input_unit'    => 'kg',
                    'qty'           => 10000, // 10,000 gram
                    'unit'          => 'gram',
                    'unit_price'    => $shopeePricePerKg,
                    'total_price'   => $totalShopeePrice,
                ]
            ]
        ]);

        $responseCreate->assertStatus(201);
        $transferData = $responseCreate->json();
        $transferId = $transferData['id'];

        // Verifikasi: Stok fisik di outlet belum bertambah (masih 0) karena barang masih dalam perjalanan
        $this->assertEquals(0, $ingredient->stockForOutlet($outlet->id));

        // 2. Kurir tiba di outlet: Staf melakukan Approval Receive
        $responseReceive = $this->actingAs($admin)->postJson("/api/transfers/{$transferId}/receive", [
            'received_notes' => 'Paket Shopee diterima utuh 10 kg',
        ]);

        $responseReceive->assertStatus(200);

        // Verifikasi: Sekarang stok fisik di outlet sudah resmi bertambah 10,000 gram!
        $freshIngredient = Ingredient::find($ingredient->id);
        $this->assertEquals(10000, $freshIngredient->stockForOutlet($outlet->id));

        // Verifikasi: Movement tercatat dengan harga beli Shopee (Rp 25.000)
        $movement = StockMovement::where('transfer_id', $transferId)->first();
        $this->assertNotNull($movement);
        $this->assertEquals('PURCHASE', $movement->type);
        $this->assertEquals(25000, $movement->unit_price);
        $this->assertEquals(250000, $movement->total_price);
        $this->assertStringContainsString('Penerimaan belanja online dari Shopee', $movement->note);
    }
}

