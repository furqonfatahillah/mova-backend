<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->enum('type', [
                'PURCHASE', 'SALE_USAGE', 'WASTE',
                'ADJUSTMENT_IN', 'ADJUSTMENT_OUT',
                'TRANSFER_IN', 'TRANSFER_OUT'
            ]);
            $table->decimal('qty', 15, 3);
            $table->string('note')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
