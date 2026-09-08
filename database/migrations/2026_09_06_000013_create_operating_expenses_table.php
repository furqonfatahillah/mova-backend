<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operating_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('expense_no', 35)->index();
            $table->date('date')->index();
            $table->string('category', 50)->index()->comment('SALARY, UTILITIES, GAS, RENT, MAINTENANCE, MARKETING, LOGISTICS, OTHER');
            $table->string('name', 255);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('payment_method', 30)->default('CASH')->comment('CASH, TRANSFER, PETTY_CASH, DEBIT');
            $table->text('notes')->nullable();
            $table->string('receipt_img', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_expenses');
    }
};
