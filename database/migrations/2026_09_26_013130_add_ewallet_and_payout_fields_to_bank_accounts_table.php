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
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('account_type', 20)->default('BANK')->after('outlet_id'); // BANK, EWALLET
            $table->string('verification_status', 20)->default('VERIFIED')->after('is_active'); // VERIFIED, PENDING, REJECTED
            $table->string('payout_schedule', 20)->default('DAILY')->after('verification_status'); // INSTANT, DAILY, MANUAL
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'verification_status', 'payout_schedule']);
        });
    }
};
