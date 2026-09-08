<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('order_number', 40)->nullable()->index()->after('id');
            $table->string('customer_name', 100)->nullable()->after('user_id');
            $table->string('order_type', 30)->default('DINE_IN')->after('customer_name');
            $table->string('table_number', 50)->nullable()->after('order_type');
            $table->string('payment_method', 30)->default('CASH')->after('table_number');
            $table->decimal('amount_paid', 15, 2)->nullable()->after('total_price');
            $table->decimal('change_amount', 15, 2)->nullable()->after('amount_paid');
            $table->text('notes')->nullable()->after('change_amount');
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete()->after('shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['outlet_id']);
            $table->dropColumn([
                'order_number',
                'customer_name',
                'order_type',
                'table_number',
                'payment_method',
                'amount_paid',
                'change_amount',
                'notes',
                'outlet_id',
            ]);
        });
    }
};
