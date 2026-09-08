<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 50)->unique();
            $table->date('date');
            $table->foreignId('source_outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignId('destination_outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->string('status', 30)->default('COMPLETED'); // PENDING, IN_TRANSIT, COMPLETED, CANCELLED
            $table->text('notes')->nullable();
            $table->string('driver_name', 100)->nullable();
            $table->string('vehicle_no', 50)->nullable();
            $table->integer('total_items')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('transfers')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('qty', 15, 3); // Jumlah dalam satuan pakai
            $table->string('unit', 30);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_items');
        Schema::dropIfExists('transfers');
    }
};
