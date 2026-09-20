<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Ingredient;
use App\Models\Opname;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OpnameDateValidationTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Outlet $outlet;
    private Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::first() ?? Business::create([
            'name' => 'Test Business',
            'code' => 'TB01',
            'slug' => 'test-biz',
        ]);

        $this->outlet = Outlet::create([
            'business_id' => $business->id,
            'name' => 'Outlet Utama Test ' . uniqid(),
            'code' => 'OUT_' . uniqid(),
            'is_main' => true,
        ]);

        $role = Role::firstOrCreate(['name' => 'Owner'], ['description' => 'Owner Bisnis']);

        $this->user = User::create([
            'business_id' => $business->id,
            'outlet_id' => $this->outlet->id,
            'name' => 'Owner Opname Test',
            'email' => 'owner_opn_' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'role' => 'owner',
        ]);

        $this->ingredient = Ingredient::create([
            'business_id' => $business->id,
            'code' => 'ING_' . uniqid(),
            'name' => 'Biji Kopi Arabika Test',
            'unit_beli' => 'kg',
            'unit_pakai' => 'gram',
            'konversi' => 1000,
            'harga' => 120000,
            'stok_awal' => 5000,
            'active' => true,
        ]);
    }

    public function test_opname_rejected_when_opname_date_is_in_the_past(): void
    {
        $yesterday = now()->subDay()->toDateString();

        $response = $this->actingAs($this->user)->postJson('/api/opnames/bulk', [
            'period_from'   => $yesterday,
            'period_to'     => $yesterday,
            'outlet_id'     => $this->outlet->id,
            'opname_date'   => $yesterday,
            'items'         => [
                [
                    'ingredient_id' => $this->ingredient->id,
                    'actual_qty'    => 5000,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Tanggal pelaksanaan opname tidak boleh di-inputkan tanggal mundur (sebelum hari ini).'
            ]);
    }

    public function test_daily_opname_succeeds_for_today(): void
    {
        $today = now()->toDateString();

        $response = $this->actingAs($this->user)->postJson('/api/opnames/bulk', [
            'period_from'   => $today,
            'period_to'     => $today,
            'outlet_id'     => $this->outlet->id,
            'opname_date'   => $today,
            'approver'      => 'Manager Toko',
            'action'        => 'DRAFT',
            'items'         => [
                [
                    'ingredient_id' => $this->ingredient->id,
                    'actual_qty'    => 4800,
                    'reason'        => 'Penyusutan normal harian',
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'opname_no',
                'is_closed',
                'status',
                'items',
            ]);

        $this->assertDatabaseHas('opnames', [
            'period_from'   => $today,
            'period_to'     => $today,
            'opname_date'   => $today,
            'outlet_id'     => $this->outlet->id,
            'ingredient_id' => $this->ingredient->id,
            'actual_qty'    => 4800,
            'is_closed'     => false,
        ]);
    }

    public function test_opname_rejected_when_date_is_earlier_than_latest_released_opname(): void
    {
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        // Simulate a released opname on tomorrow (future date)
        Opname::create([
            'business_id'   => $this->user->business_id,
            'opname_no'     => 'OPN-RELEASED-' . uniqid(),
            'opname_date'   => $tomorrow,
            'period_from'   => $tomorrow,
            'period_to'     => $tomorrow,
            'outlet_id'     => $this->outlet->id,
            'ingredient_id' => $this->ingredient->id,
            'actual_qty'    => 4500,
            'is_closed'     => true,
            'user_id'       => $this->user->id,
        ]);

        // Attempting to create an opname today (which is earlier than tomorrow's released opname)
        $response = $this->actingAs($this->user)->postJson('/api/opnames/bulk', [
            'period_from'   => $today,
            'period_to'     => $today,
            'outlet_id'     => $this->outlet->id,
            'opname_date'   => $today,
            'items'         => [
                [
                    'ingredient_id' => $this->ingredient->id,
                    'actual_qty'    => 4600,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('tidak boleh mundur', $response->json('message'));
    }
}
