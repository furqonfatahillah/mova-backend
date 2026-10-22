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
        if (Schema::hasTable('referral_codes')) {
            Schema::table('referral_codes', function (Blueprint $table) {
                if (!Schema::hasColumn('referral_codes', 'bank_name')) {
                    $table->string('bank_name', 100)->nullable()->after('recipient_name')->comment('Bank atau E-Wallet tujuan (BCA, Mandiri, BRI, DANA, GoPay, dll)');
                }
                if (!Schema::hasColumn('referral_codes', 'bank_account_number')) {
                    $table->string('bank_account_number', 100)->nullable()->after('bank_name')->comment('Nomor rekening atau nomor HP e-wallet');
                }
                if (!Schema::hasColumn('referral_codes', 'bank_account_name')) {
                    $table->string('bank_account_name', 150)->nullable()->after('bank_account_number')->comment('Atas nama pemilik rekening/e-wallet');
                }
                if (!Schema::hasColumn('referral_codes', 'whatsapp_number')) {
                    $table->string('whatsapp_number', 50)->nullable()->after('bank_account_name')->comment('Nomor WhatsApp Mitra untuk notifikasi / komunikasi');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('referral_codes')) {
            Schema::table('referral_codes', function (Blueprint $table) {
                $columns = ['bank_name', 'bank_account_number', 'bank_account_name', 'whatsapp_number'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('referral_codes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
