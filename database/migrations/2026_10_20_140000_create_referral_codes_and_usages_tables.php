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
        // 1. Create referral_codes table (managed exclusively by Superadmin)
        if (!Schema::hasTable('referral_codes')) {
            Schema::create('referral_codes', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('recipient_name', 255)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('max_uses')->nullable()->comment('Null means unlimited uses; 1 means single exclusive use');
                $table->unsignedInteger('times_used')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamp('expires_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('code');
                $table->index('is_active');
            });
        }

        // 2. Create referral_usages table to track every registration that used a referral code
        if (!Schema::hasTable('referral_usages')) {
            Schema::create('referral_usages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('referral_code_id')->constrained('referral_codes')->cascadeOnDelete();
                $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('registered_at')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['referral_code_id', 'business_id']);
            });
        }

        // 3. Link businesses to referral_codes
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'referral_code_id')) {
                $table->foreignId('referral_code_id')->nullable()->after('referred_by_id')->constrained('referral_codes')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'referral_code_id')) {
                $table->dropForeign(['referral_code_id']);
                $table->dropColumn('referral_code_id');
            }
        });

        Schema::dropIfExists('referral_usages');
        Schema::dropIfExists('referral_codes');
    }
};
