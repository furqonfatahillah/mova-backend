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
        // 1. Add total_commission_paid to referral_codes
        if (Schema::hasTable('referral_codes') && !Schema::hasColumn('referral_codes', 'total_commission_paid')) {
            Schema::table('referral_codes', function (Blueprint $table) {
                $table->decimal('total_commission_paid', 15, 2)->default(0.00)->after('total_commission_earned');
            });
        }

        // 2. Create referral_commission_payouts table
        if (!Schema::hasTable('referral_commission_payouts')) {
            Schema::create('referral_commission_payouts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('referral_code_id')->constrained('referral_codes')->onDelete('cascade');
                $table->string('payout_no', 50)->unique();
                $table->decimal('amount', 15, 2);
                $table->string('payment_method', 30)->default('BANK_TRANSFER'); // BANK_TRANSFER, CASH, E_WALLET, OTHER
                $table->string('bank_name', 50)->nullable();
                $table->string('account_number', 50)->nullable();
                $table->string('account_name', 100)->nullable();
                $table->date('payment_date');
                $table->string('reference_no', 100)->nullable();
                $table->text('notes')->nullable();
                $table->string('proof_image', 255)->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamps();

                $table->index(['referral_code_id', 'payment_date']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_commission_payouts');

        if (Schema::hasTable('referral_codes') && Schema::hasColumn('referral_codes', 'total_commission_paid')) {
            Schema::table('referral_codes', function (Blueprint $table) {
                $table->dropColumn('total_commission_paid');
            });
        }
    }
};
