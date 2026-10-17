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
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'client_order_id')) {
                $table->string('client_order_id', 100)->nullable()->after('order_number')->index();
                $table->index(['business_id', 'client_order_id'], 'idx_tx_biz_client_order_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'client_order_id')) {
                $table->dropIndex('idx_tx_biz_client_order_id');
                $table->dropColumn('client_order_id');
            }
        });
    }
};
