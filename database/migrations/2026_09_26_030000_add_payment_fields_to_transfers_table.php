<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('transfers', 'payment_type')) {
                $table->string('payment_type', 30)->default('INTERNAL')->after('status'); // INTERNAL, CASH, BANK, TRANSFER, QRIS, HUTANG
            }
            if (!Schema::hasColumn('transfers', 'payment_method')) {
                $table->string('payment_method', 50)->nullable()->after('payment_type');
            }
            if (!Schema::hasColumn('transfers', 'total_amount')) {
                $table->decimal('total_amount', 15, 2)->default(0)->after('payment_method');
            }
            if (!Schema::hasColumn('transfers', 'supplier_name')) {
                $table->string('supplier_name', 150)->nullable()->after('total_amount');
            }
            if (!Schema::hasColumn('transfers', 'purchase_no')) {
                $table->string('purchase_no', 100)->nullable()->after('supplier_name');
            }
            if (!Schema::hasColumn('transfers', 'due_date')) {
                $table->date('due_date')->nullable()->after('purchase_no');
            }
            if (!Schema::hasColumn('transfers', 'initial_paid')) {
                $table->decimal('initial_paid', 15, 2)->default(0)->after('due_date');
            }
            if (!Schema::hasColumn('transfers', 'payable_id')) {
                $table->foreignId('payable_id')->nullable()->after('initial_paid')->constrained('payables')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            if (Schema::hasColumn('transfers', 'payable_id')) {
                $table->dropForeign(['payable_id']);
                $table->dropColumn('payable_id');
            }
            if (Schema::hasColumn('transfers', 'initial_paid')) {
                $table->dropColumn('initial_paid');
            }
            if (Schema::hasColumn('transfers', 'due_date')) {
                $table->dropColumn('due_date');
            }
            if (Schema::hasColumn('transfers', 'purchase_no')) {
                $table->dropColumn('purchase_no');
            }
            if (Schema::hasColumn('transfers', 'supplier_name')) {
                $table->dropColumn('supplier_name');
            }
            if (Schema::hasColumn('transfers', 'total_amount')) {
                $table->dropColumn('total_amount');
            }
            if (Schema::hasColumn('transfers', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
            if (Schema::hasColumn('transfers', 'payment_type')) {
                $table->dropColumn('payment_type');
            }
        });
    }
};
