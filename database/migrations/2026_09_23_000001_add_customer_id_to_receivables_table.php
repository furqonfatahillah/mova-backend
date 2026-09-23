<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            if (!Schema::hasColumn('receivables', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('transaction_id')->constrained('customers')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            if (Schema::hasColumn('receivables', 'customer_id')) {
                $table->dropForeign(['customer_id']);
                $table->dropColumn('customer_id');
            }
        });
    }
};
