<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('subtotal', 15, 2)->nullable()->after('total_price');
            $table->foreignId('discount_id')->nullable()->after('subtotal')->constrained('discounts')->nullOnDelete();
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_id');
            $table->string('discount_name', 100)->nullable()->after('discount_amount');
            $table->string('discount_type', 30)->nullable()->after('discount_name');
            $table->decimal('discount_rate', 15, 2)->nullable()->after('discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
            $table->dropColumn([
                'subtotal',
                'discount_id',
                'discount_amount',
                'discount_name',
                'discount_type',
                'discount_rate',
            ]);
        });
    }
};
