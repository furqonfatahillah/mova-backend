<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            if (!Schema::hasColumn('receivables', 'ar_type')) {
                $table->string('ar_type', 30)->default('CUSTOMER')->index()->after('customer_id'); // CUSTOMER, MERCHANT_QRIS, MERCHANT_ECOMMERCE
            }
            if (!Schema::hasColumn('receivables', 'merchant_channel')) {
                $table->string('merchant_channel', 50)->nullable()->index()->after('ar_type'); // QRIS, GRAB, GOFOOD, SHOPEEFOOD, ECOMMERCE, etc.
            }
            if (!Schema::hasColumn('receivables', 'mdr_rate')) {
                $table->decimal('mdr_rate', 5, 2)->default(0)->after('remaining_amount');
            }
            if (!Schema::hasColumn('receivables', 'mdr_fee')) {
                $table->decimal('mdr_fee', 15, 2)->default(0)->after('mdr_rate');
            }
            if (!Schema::hasColumn('receivables', 'net_amount')) {
                $table->decimal('net_amount', 15, 2)->default(0)->after('mdr_fee');
            }
            if (!Schema::hasColumn('receivables', 'settlement_status')) {
                $table->string('settlement_status', 30)->default('UNSETTLED')->index()->after('status'); // UNSETTLED, SETTLED
            }
            if (!Schema::hasColumn('receivables', 'settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('settlement_status');
            }
            if (!Schema::hasColumn('receivables', 'settlement_bank')) {
                $table->string('settlement_bank', 100)->nullable()->after('settled_at');
            }
            if (!Schema::hasColumn('receivables', 'settlement_ref')) {
                $table->string('settlement_ref', 100)->nullable()->after('settlement_bank');
            }
        });
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $cols = ['ar_type', 'merchant_channel', 'mdr_rate', 'mdr_fee', 'net_amount', 'settlement_status', 'settled_at', 'settlement_bank', 'settlement_ref'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('receivables', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
