<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create Suppliers Table
        if (!Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
                $table->string('name', 150);
                $table->string('phone', 50)->nullable();
                $table->string('contact_person', 100)->nullable();
                $table->text('address')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index(['business_id', 'name']);
            });
        }

        // 2. Create Payables Table (Hutang Supplier)
        if (!Schema::hasTable('payables')) {
            Schema::create('payables', function (Blueprint $table) {
                $table->id();
                $table->string('payable_no', 50)->index(); // HUT-YYYYMMDD-0001
                $table->string('purchase_no', 100)->nullable()->index(); // No. Pembelian / Invoice / PO
                $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
                $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->string('supplier_name', 150);
                $table->string('supplier_phone', 50)->nullable();
                $table->text('supplier_address')->nullable();
                $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
                $table->foreignId('ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();
                $table->date('issue_date'); // Tgl. Dibuat
                $table->date('due_date'); // Jatuh Tempo
                $table->decimal('total_amount', 15, 2)->default(0); // Hutang
                $table->decimal('paid_amount', 15, 2)->default(0); // Dibayar
                $table->decimal('remaining_amount', 15, 2)->default(0); // Sisa Hutang
                $table->string('status', 30)->default('UNPAID')->index(); // UNPAID, PARTIAL, PAID, OVERDUE, CANCELLED
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['outlet_id', 'status']);
                $table->index(['business_id', 'status']);
                $table->index(['outlet_id', 'due_date']);
                $table->index(['issue_date', 'due_date']);
            });
        }

        // 3. Create Payable Payments Table (Riwayat Cicilan / Pembayaran Hutang)
        if (!Schema::hasTable('payable_payments')) {
            Schema::create('payable_payments', function (Blueprint $table) {
                $table->id();
                $table->string('payment_no', 50)->index(); // BYR-HUT-YYYYMMDD-0001
                $table->foreignId('payable_id')->constrained('payables')->cascadeOnDelete();
                $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
                $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
                $table->date('payment_date');
                $table->decimal('amount', 15, 2)->default(0);
                $table->string('payment_method', 50)->default('CASH'); // CASH, TRANSFER, PETTY_CASH, OTHER
                $table->string('reference_no', 100)->nullable(); // Bukti Transfer / No. Rekening
                $table->text('notes')->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['payable_id', 'payment_date']);
                $table->index(['outlet_id', 'payment_date']);
            });
        }

        // 4. Add Payment & Payable Fields to Stock Movements Table
        Schema::table('stock_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_movements', 'payment_type')) {
                $table->string('payment_type', 30)->default('CASH')->after('type'); // CASH, TRANSFER, HUTANG
            }
            if (!Schema::hasColumn('stock_movements', 'supplier_name')) {
                $table->string('supplier_name', 150)->nullable()->after('payment_type');
            }
            if (!Schema::hasColumn('stock_movements', 'purchase_no')) {
                $table->string('purchase_no', 100)->nullable()->after('supplier_name');
            }
            if (!Schema::hasColumn('stock_movements', 'payable_id')) {
                $table->foreignId('payable_id')->nullable()->after('purchase_no')->constrained('payables')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            if (Schema::hasColumn('stock_movements', 'payable_id')) {
                $table->dropForeign(['payable_id']);
                $table->dropColumn('payable_id');
            }
            if (Schema::hasColumn('stock_movements', 'purchase_no')) {
                $table->dropColumn('purchase_no');
            }
            if (Schema::hasColumn('stock_movements', 'supplier_name')) {
                $table->dropColumn('supplier_name');
            }
            if (Schema::hasColumn('stock_movements', 'payment_type')) {
                $table->dropColumn('payment_type');
            }
        });

        Schema::dropIfExists('payable_payments');
        Schema::dropIfExists('payables');
        Schema::dropIfExists('suppliers');
    }
};
