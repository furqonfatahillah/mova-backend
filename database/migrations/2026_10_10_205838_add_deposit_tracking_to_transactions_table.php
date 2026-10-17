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
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_deposited')->default(false)->index()->after('notes');
            $table->decimal('deposited_amount', 15, 2)->default(0)->after('is_deposited');
            $table->timestamp('deposited_at')->nullable()->after('deposited_amount');
            $table->foreignId('deposit_cash_transaction_id')->nullable()->after('deposited_at')->constrained('cash_transactions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['deposit_cash_transaction_id']);
            $table->dropColumn(['deposit_cash_transaction_id', 'deposited_at', 'deposited_amount', 'is_deposited']);
        });
    }
};
