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
        // 1. Add commission settings and tracked earnings to referral_codes table
        Schema::table('referral_codes', function (Blueprint $table) {
            if (!Schema::hasColumn('referral_codes', 'commission_percent')) {
                $table->decimal('commission_percent', 5, 2)->default(0.00)->after('recipient_name');
            }
            if (!Schema::hasColumn('referral_codes', 'total_rupiah_topup')) {
                $table->decimal('total_rupiah_topup', 15, 2)->default(0.00)->after('times_used');
            }
            if (!Schema::hasColumn('referral_codes', 'total_commission_earned')) {
                $table->decimal('total_commission_earned', 15, 2)->default(0.00)->after('total_rupiah_topup');
            }
        });

        // 2. Create coin_topup_requests table
        if (!Schema::hasTable('coin_topup_requests')) {
            Schema::create('coin_topup_requests', function (Blueprint $table) {
                $table->id();
                $table->string('request_no', 50)->unique();
                $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('referral_code_id')->nullable()->constrained('referral_codes')->nullOnDelete();
                
                $table->decimal('amount_rupiah', 15, 2);
                $table->decimal('rate_per_coin', 10, 2)->default(100.00); // 100 rupiah per koin
                $table->decimal('amount_coins', 14, 2); // amount_rupiah / rate_per_coin
                
                $table->string('bank_name', 50)->default('Bank Mandiri');
                $table->string('bank_account_name', 100)->default('MUH AL RIADIN ANGGA');
                $table->string('bank_account_number', 50)->default('1740007745029');
                $table->string('transfer_proof_url', 255)->nullable();
                
                $table->enum('status', ['pending', 'approved', 'auto_approved', 'rejected'])->default('pending');
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                
                $table->decimal('commission_percent_applied', 5, 2)->default(0.00);
                $table->decimal('commission_rupiah_earned', 15, 2)->default(0.00);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('business_id');
                $table->index('referral_code_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coin_topup_requests');

        Schema::table('referral_codes', function (Blueprint $table) {
            $table->dropColumn(['commission_percent', 'total_rupiah_topup', 'total_commission_earned']);
        });
    }
};
