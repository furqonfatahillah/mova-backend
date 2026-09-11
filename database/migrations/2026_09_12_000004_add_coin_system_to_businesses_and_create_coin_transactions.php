<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add coin balance and rate configuration to businesses
        Schema::table('businesses', function (Blueprint $table) {
            $table->decimal('coin_balance', 14, 2)->default(0.00)->after('status');
            $table->decimal('coins_per_transaction', 8, 2)->default(1.00)->after('coin_balance');
            $table->integer('low_coin_threshold')->default(20)->after('coins_per_transaction');
        });

        // 2. Create coin ledger table for audit trail (top up, usage per nota, adjustment)
        Schema::create('coin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->enum('type', ['TOPUP', 'USAGE', 'ADJUSTMENT', 'REFUND'])->default('USAGE');
            $table->decimal('amount', 14, 2); // Positive for topup/refund, negative for usage
            $table->decimal('balance_before', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->decimal('payment_amount', 15, 2)->nullable(); // Real money transfer received by website owner
            $table->string('payment_reference')->nullable(); // Bank ref, transfer proof, etc.
            $table->string('order_number')->nullable(); // Order number for USAGE
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'type']);
            $table->index(['business_id', 'created_at']);
            $table->index('order_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coin_transactions');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['coin_balance', 'coins_per_transaction', 'low_coin_threshold']);
        });
    }
};
