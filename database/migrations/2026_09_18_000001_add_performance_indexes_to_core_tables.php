<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Helper to safely add index if not already present
     */
    private function addIndexSafely(string $table, array $columns, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                $t->index($columns, $indexName);
            });
        } catch (\Throwable $e) {
            // Index already exists or column doesn't exist, safely ignore
        }
    }

    /**
     * Helper to safely drop index if present
     */
    private function dropIndexSafely(string $table, string $indexName): void
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($indexName) {
                $t->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            // Index doesn't exist, safely ignore
        }
    }

    /**
     * Run the migrations to add performance indexes across core transaction & ledger tables.
     */
    public function up(): void
    {
        // 1. Transactions Table
        if (Schema::hasTable('transactions')) {
            $this->addIndexSafely('transactions', ['business_id', 'outlet_id', 'date', 'status'], 'idx_trx_biz_out_date_stat');
            $this->addIndexSafely('transactions', ['date', 'status'], 'idx_trx_date_stat');
            $this->addIndexSafely('transactions', ['order_number'], 'idx_trx_order_number');
            $this->addIndexSafely('transactions', ['parent_order_number'], 'idx_trx_parent_order_number');
            $this->addIndexSafely('transactions', ['shift_id'], 'idx_trx_shift_id');
        }

        // 2. Stock Movements Table
        if (Schema::hasTable('stock_movements')) {
            $this->addIndexSafely('stock_movements', ['ingredient_id', 'outlet_id'], 'idx_sm_ing_out');
            $this->addIndexSafely('stock_movements', ['business_id', 'outlet_id', 'date'], 'idx_sm_biz_out_date');
            $this->addIndexSafely('stock_movements', ['type'], 'idx_sm_type');
            $this->addIndexSafely('stock_movements', ['transaction_id'], 'idx_sm_trx_id');
        }

        // 3. Opnames Table
        if (Schema::hasTable('opnames')) {
            $this->addIndexSafely('opnames', ['business_id', 'outlet_id', 'opname_date'], 'idx_opn_biz_out_date');
            $this->addIndexSafely('opnames', ['period_from', 'period_to'], 'idx_opn_periods');
            $this->addIndexSafely('opnames', ['ingredient_id', 'outlet_id'], 'idx_opn_ing_out');
            $this->addIndexSafely('opnames', ['is_closed'], 'idx_opn_is_closed');
        }

        // 4. Operating Expenses Table
        if (Schema::hasTable('operating_expenses')) {
            $this->addIndexSafely('operating_expenses', ['business_id', 'outlet_id', 'date'], 'idx_opex_biz_out_date');
            $this->addIndexSafely('operating_expenses', ['category'], 'idx_opex_category');
        }

        // 5. Waste Logs Table
        if (Schema::hasTable('waste_logs')) {
            $this->addIndexSafely('waste_logs', ['business_id', 'outlet_id', 'date'], 'idx_waste_biz_out_date');
            $this->addIndexSafely('waste_logs', ['reason_category'], 'idx_waste_reason_cat');
        }

        // 6. Cash Transactions Table
        if (Schema::hasTable('cash_transactions')) {
            $this->addIndexSafely('cash_transactions', ['business_id', 'outlet_id', 'date'], 'idx_cash_biz_out_date');
        }

        // 7. Urgent Notes Table
        if (Schema::hasTable('urgent_notes')) {
            $this->addIndexSafely('urgent_notes', ['business_id', 'outlet_id', 'status'], 'idx_unote_biz_out_stat');
            $this->addIndexSafely('urgent_notes', ['transaction_id'], 'idx_unote_trx_id');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropIndexSafely('transactions', 'idx_trx_biz_out_date_stat');
        $this->dropIndexSafely('transactions', 'idx_trx_date_stat');
        $this->dropIndexSafely('transactions', 'idx_trx_order_number');
        $this->dropIndexSafely('transactions', 'idx_trx_parent_order_number');
        $this->dropIndexSafely('transactions', 'idx_trx_shift_id');

        $this->dropIndexSafely('stock_movements', 'idx_sm_ing_out');
        $this->dropIndexSafely('stock_movements', 'idx_sm_biz_out_date');
        $this->dropIndexSafely('stock_movements', 'idx_sm_type');
        $this->dropIndexSafely('stock_movements', 'idx_sm_trx_id');

        $this->dropIndexSafely('opnames', 'idx_opn_biz_out_date');
        $this->dropIndexSafely('opnames', 'idx_opn_periods');
        $this->dropIndexSafely('opnames', 'idx_opn_ing_out');
        $this->dropIndexSafely('opnames', 'idx_opn_is_closed');

        $this->dropIndexSafely('operating_expenses', 'idx_opex_biz_out_date');
        $this->dropIndexSafely('operating_expenses', 'idx_opex_category');

        $this->dropIndexSafely('waste_logs', 'idx_waste_biz_out_date');
        $this->dropIndexSafely('waste_logs', 'idx_waste_reason_cat');

        $this->dropIndexSafely('cash_transactions', 'idx_cash_biz_out_date');

        $this->dropIndexSafely('urgent_notes', 'idx_unote_biz_out_stat');
        $this->dropIndexSafely('urgent_notes', 'idx_unote_trx_id');
    }
};
