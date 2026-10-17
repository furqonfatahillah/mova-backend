<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Creates dedicated Saldo Awal table (Table 2 of 3)
     * 1. ingredients (Master Bahan)
     * 2. ingredient_initial_balances (Saldo Awal per Cabang)
     * 3. outlet_ingredients (Saldo Berjalan per Cabang)
     */
    public function up(): void
    {
        if (!Schema::hasTable('ingredient_initial_balances')) {
            Schema::create('ingredient_initial_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->nullable();
                $table->unsignedBigInteger('outlet_id');
                $table->unsignedBigInteger('ingredient_id');
                $table->decimal('stok_awal', 15, 4)->default(0);
                $table->decimal('saldo_awal_nominal', 15, 2)->default(0);
                $table->date('tanggal_saldo_awal')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->foreign('business_id')->references('id')->on('businesses')->onDelete('cascade');
                $table->foreign('outlet_id')->references('id')->on('outlets')->onDelete('cascade');
                $table->foreign('ingredient_id')->references('id')->on('ingredients')->onDelete('cascade');

                $table->unique(['outlet_id', 'ingredient_id'], 'uq_outlet_ing_initial');
                $table->index(['outlet_id', 'ingredient_id']);
            });
        }

        // Migrate all existing initial balance records from outlet_ingredients
        $outlets = DB::table('outlets')->get();
        $ingredients = DB::table('ingredients')->get();

        foreach ($ingredients as $ing) {
            foreach ($outlets as $ot) {
                $oi = DB::table('outlet_ingredients')
                    ->where('ingredient_id', $ing->id)
                    ->where('outlet_id', $ot->id)
                    ->first();

                $stokAwal = $oi ? (float)($oi->stok_awal ?? 0) : 0.0;
                $saldoNominal = $oi ? (float)($oi->saldo_awal_nominal ?? 0) : 0.0;
                $tanggal = $oi?->tanggal_saldo_awal ?? $ing->tanggal_saldo_awal ?? now()->toDateString();

                DB::table('ingredient_initial_balances')->updateOrInsert(
                    [
                        'outlet_id'     => $ot->id,
                        'ingredient_id' => $ing->id,
                    ],
                    [
                        'business_id'        => $ing->business_id ?? $ot->business_id ?? 1,
                        'stok_awal'          => $stokAwal,
                        'saldo_awal_nominal' => $saldoNominal,
                        'tanggal_saldo_awal' => $tanggal,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_initial_balances');
    }
};
