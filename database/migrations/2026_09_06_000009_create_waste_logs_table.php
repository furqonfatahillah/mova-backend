<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waste_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('waste_no', 35)->index();
            $table->date('date');
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            
            // Kuantitas & Biaya
            $table->decimal('qty', 15, 3)->comment('Kuantitas yang diinput pengguna');
            $table->string('unit_type', 10)->default('PAKAI')->comment('BELI atau PAKAI');
            $table->decimal('qty_pakai', 15, 3)->comment('Kuantitas pemotongan dalam satuan pakai');
            $table->decimal('cost_per_unit', 15, 2)->default(0)->comment('HPP per unit pakai');
            $table->decimal('loss_cost', 15, 2)->default(0)->comment('Total kerugian rupiah (qty_pakai * cost_per_unit)');
            
            // Kategori Alasan & Detail
            $table->string('reason_category', 50)->comment('EXPIRED, COOKING_ERROR, DELIVERY_DAMAGE, CUSTOMER_COMPLAINT, DROPPED_SPILL, STORAGE_DAMAGE, OTHER');
            $table->text('notes')->nullable()->comment('Kronologi / keterangan detail kejadian');
            $table->string('action_taken')->nullable()->comment('Tindakan korektif: dibuang, retur supplier, teguran koki, dll');
            
            // Relasi Audit & Pergerakan Stok
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_logs');
    }
};
