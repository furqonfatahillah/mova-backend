<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->string('receivable_no', 50)->index();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('order_number', 50)->nullable()->index();
            $table->string('customer_name', 150);
            $table->string('customer_phone', 50)->nullable();
            $table->text('customer_address')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            $table->string('status', 30)->default('UNPAID')->index(); // UNPAID, PARTIAL, PAID, OVERDUE, CANCELLED
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['outlet_id', 'status']);
            $table->index(['business_id', 'status']);
            $table->index(['outlet_id', 'due_date']);
        });

        Schema::create('receivable_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 50)->index();
            $table->foreignId('receivable_id')->constrained('receivables')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('payment_method', 50)->default('CASH'); // CASH, TRANSFER, QRIS, DEBIT, OTHER
            $table->string('reference_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['receivable_id', 'payment_date']);
            $table->index(['outlet_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_payments');
        Schema::dropIfExists('receivables');
    }
};
