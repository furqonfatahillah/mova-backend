<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\Payable;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class PurchaseReportTest extends TestCase
{
    use DatabaseTransactions;
    public function test_purchase_reports_endpoints()
    {
        $business = Business::first() ?: Business::create([
            'name' => 'Test Business',
            'package_type' => 'enterprise',
            'status' => 'active',
        ]);

        $holding = Outlet::firstOrCreate(
            ['code' => 'OUT-TEST-MAIN'],
            ['name' => 'Holding Test', 'is_main' => true, 'business_id' => $business->id]
        );

        $branch = Outlet::firstOrCreate(
            ['code' => 'OUT-TEST-BRANCH'],
            ['name' => 'Cabang Test', 'is_main' => false, 'business_id' => $business->id]
        );

        $user = User::first() ?: User::create([
            'name' => 'Admin Test',
            'email' => 'admin_test@mova.id',
            'password' => bcrypt('password'),
            'role' => 'owner_bisnis',
            'business_id' => $business->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->getJson('/api/reports/purchase/transactions?from=2026-09-01&to=2026-09-30');
        $response->assertStatus(200);
        $response->assertJsonStructure(['business_name', 'report_title', 'period', 'summary', 'items']);

        $response = $this->actingAs($user)->getJson('/api/reports/purchase/by-product?from=2026-09-01&to=2026-09-30');
        $response->assertStatus(200);
        $response->assertJsonStructure(['report_title', 'summary', 'items']);

        $response = $this->actingAs($user)->getJson('/api/reports/purchase/by-supplier?from=2026-09-01&to=2026-09-30');
        $response->assertStatus(200);
        $response->assertJsonStructure(['report_title', 'summary', 'items']);

        $response = $this->actingAs($user)->getJson('/api/reports/purchase/payables?from=2026-09-01&to=2026-09-30');
        $response->assertStatus(200);
        $response->assertJsonStructure(['report_title', 'summary', 'items']);

        $response = $this->actingAs($user)->getJson('/api/reports/purchase/shipments?from=2026-09-01&to=2026-09-30');
        $response->assertStatus(200);
        $response->assertJsonStructure(['report_title', 'summary', 'items']);
    }

    public function test_holding_vs_outlet_purchase_policy()
    {
        $business = Business::first();
        $holding = Outlet::where('is_main', true)->first();
        $branch = Outlet::where('is_main', false)->first();

        $ingredient = Ingredient::first() ?: Ingredient::create([
            'business_id' => $business->id,
            'name' => 'Biji Kopi Arabika Test',
            'code' => 'TEST-ING-001',
            'unit_beli' => 'Kg',
            'unit_pakai' => 'gram',
            'konversi' => 1000,
            'harga' => 150000,
        ]);

        $user = User::where('role', 'owner_bisnis')->first() ?: User::firstOrCreate(
            ['email' => 'admin_test@mova.id'],
            [
                'name' => 'Admin Test',
                'password' => bcrypt('password'),
                'role' => 'owner_bisnis',
                'business_id' => $business->id,
                'status' => 'active',
            ]
        );

        // 1. Purchase for Branch Outlet with HUTANG requested -> should be forced to CASH
        $resBranch = $this->actingAs($user)->postJson('/api/movements', [
            'date' => now()->toDateString(),
            'outlet_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'type' => 'PURCHASE',
            'qty' => 1,
            'unit_type' => 'BELI',
            'payment_type' => 'HUTANG', // Attempting Hutang on branch
            'unit_price' => 150000,
        ]);
        $resBranch->assertStatus(201);
        $movementBranch = StockMovement::find($resBranch->json('id'));
        $this->assertEquals('CASH', $movementBranch->payment_type, 'Outlet cabang purchases must strictly be CASH only');
        $this->assertNull($movementBranch->payable_id, 'Outlet cabang must not generate payables');

        // 2. Purchase for Holding Outlet with HUTANG requested -> should allow HUTANG and create Payable
        $resHolding = $this->actingAs($user)->postJson('/api/movements', [
            'date' => now()->toDateString(),
            'outlet_id' => $holding->id,
            'ingredient_id' => $ingredient->id,
            'type' => 'PURCHASE',
            'qty' => 2,
            'unit_type' => 'BELI',
            'payment_type' => 'HUTANG',
            'supplier_name' => 'Supplier PT Kopi Holding',
            'unit_price' => 150000,
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $resHolding->assertStatus(201);
        $movementHolding = StockMovement::find($resHolding->json('id'));
        $this->assertEquals('HUTANG', $movementHolding->payment_type, 'Holding purchases can be HUTANG');
        $this->assertNotNull($movementHolding->payable_id, 'Holding hutang purchase must generate a linked payable');
    }
}
