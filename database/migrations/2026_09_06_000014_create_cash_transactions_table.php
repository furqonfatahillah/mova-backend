<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('transaction_no', 35)->index();
            $table->date('date')->index();
            $table->string('type', 10)->comment('IN, OUT');
            $table->string('activity_type', 20)->index()->comment('OPERATING, INVESTING, FINANCING');
            $table->string('category', 50)->index()->comment('EQUIPMENT, RENOVATION, FURNITURE, TECH_POS, ASSET_SALE, CAPITAL_INJECTION, OWNER_WITHDRAWAL, LOAN_RECEIPT, LOAN_REPAYMENT, SUPPLIER_PURCHASE, OTHER_INCOME, OTHER_EXPENSE');
            $table->string('name', 255);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('account', 30)->default('BANK_MAIN')->comment('CASH_DRAWER, BANK_MAIN, PETTY_CASH');
            $table->string('payment_method', 30)->default('TRANSFER')->comment('CASH, TRANSFER, DEBIT');
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
        Schema::dropIfExists('cash_transactions');
    }
};
