<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('shift_name'); // e.g. "Shift 1 (Pagi)", "Shift 2 (Siang)", etc.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Cashier / Employee
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('initial_cash', 15, 2)->default(0); // Modal awal kas
            $table->decimal('system_cash', 15, 2)->default(0); // Total penjualan tunai/sistem
            $table->decimal('closing_cash', 15, 2)->nullable(); // Uang fisik aktual saat closing
            $table->decimal('cash_difference', 15, 2)->nullable(); // Selisih kas
            $table->enum('status', ['OPEN', 'CLOSED'])->default('OPEN');
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
