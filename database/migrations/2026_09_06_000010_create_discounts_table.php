<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('name', 100);
            $table->string('code', 50)->nullable()->index();
            $table->enum('type', ['PERCENTAGE', 'FIXED'])->default('PERCENTAGE');
            $table->decimal('value', 15, 2)->default(0);
            $table->enum('scope', ['TRANSACTION', 'CATEGORY', 'MENU_ITEM'])->default('TRANSACTION');
            $table->unsignedBigInteger('scope_target_id')->nullable();
            $table->decimal('min_order_amount', 15, 2)->default(0);
            $table->decimal('max_discount_amount', 15, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('used_count')->default(0);
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->boolean('is_auto_apply')->default(false);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
