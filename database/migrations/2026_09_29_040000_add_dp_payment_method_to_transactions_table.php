<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'dp_payment_method')) {
                $table->string('dp_payment_method', 50)->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('transactions', 'dp_reference_no')) {
                $table->string('dp_reference_no', 100)->nullable()->after('dp_payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'dp_reference_no')) {
                $table->dropColumn('dp_reference_no');
            }
            if (Schema::hasColumn('transactions', 'dp_payment_method')) {
                $table->dropColumn('dp_payment_method');
            }
        });
    }
};
