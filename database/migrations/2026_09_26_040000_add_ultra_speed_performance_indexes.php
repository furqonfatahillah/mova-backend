<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Helper to safely add composite index if not already present
     */
    private function addIndexSafely(string $table, array $columns, string $indexName): void
    {
        try {
            if (Schema::hasTable($table)) {
                // Check that all columns exist in the table before attempting to index
                foreach ($columns as $col) {
                    if (!Schema::hasColumn($table, $col)) {
                        return;
                    }
                }
                Schema::table($table, function (Blueprint $t) use ($columns, $indexName) {
                    $t->index($columns, $indexName);
                });
            }
        } catch (\Throwable $e) {
            // Index already exists or driver error, ignore safely
        }
    }

    /**
     * Helper to safely drop index if present
     */
    private function dropIndexSafely(string $table, string $indexName): void
    {
        try {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $t) use ($indexName) {
                    $t->dropIndex($indexName);
                });
            }
        } catch (\Throwable $e) {
            // Index doesn't exist, ignore safely
        }
    }

    /**
     * Run the migrations for ultra fast database queries and instant response times.
     */
    public function up(): void
    {
        // 1. Payables & Payments
        $this->addIndexSafely('payables', ['business_id', 'status', 'due_date'], 'idx_pay_biz_stat_due');
        $this->addIndexSafely('payables', ['outlet_id', 'status'], 'idx_pay_out_stat');
        $this->addIndexSafely('payables', ['supplier_name'], 'idx_pay_supp_name');
        $this->addIndexSafely('payable_payments', ['payable_id'], 'idx_paypmt_pay_id');
        $this->addIndexSafely('payable_payments', ['payment_date'], 'idx_paypmt_date');

        // 2. Transfers & Transfer Items
        $this->addIndexSafely('transfers', ['business_id', 'status', 'date'], 'idx_trf_biz_stat_date');
        $this->addIndexSafely('transfers', ['source_outlet_id', 'destination_outlet_id'], 'idx_trf_src_dest');
        $this->addIndexSafely('transfer_items', ['transfer_id', 'ingredient_id'], 'idx_trfitm_trf_ing');

        // 3. Stock Movements & Outlet Ingredients
        $this->addIndexSafely('stock_movements', ['ingredient_id', 'outlet_id', 'date'], 'idx_mov_ing_out_date');
        $this->addIndexSafely('stock_movements', ['business_id', 'outlet_id', 'type'], 'idx_mov_biz_out_type');
        $this->addIndexSafely('stock_movements', ['type', 'date'], 'idx_mov_type_date');
        $this->addIndexSafely('outlet_ingredients', ['outlet_id', 'ingredient_id'], 'idx_oting_out_ing');

        // 4. Waste Logs & Batch Preps
        $this->addIndexSafely('waste_logs', ['business_id', 'outlet_id', 'date'], 'idx_wst_biz_out_date');
        $this->addIndexSafely('waste_logs', ['ingredient_id'], 'idx_wst_ing_id');
        $this->addIndexSafely('waste_logs', ['menu_id'], 'idx_wst_menu_id');
        $this->addIndexSafely('batch_preps', ['business_id', 'outlet_id', 'date'], 'idx_prep_biz_out_date');

        // 5. Cash Transactions & Operating Expenses
        $this->addIndexSafely('cash_transactions', ['business_id', 'outlet_id', 'date'], 'idx_cashtx_biz_out_date');
        $this->addIndexSafely('cash_transactions', ['type', 'date'], 'idx_cashtx_type_date');
        $this->addIndexSafely('operating_expenses', ['business_id', 'outlet_id', 'date'], 'idx_opex_biz_out_date');

        // 6. Customers & Receivables
        $this->addIndexSafely('customers', ['business_id', 'phone'], 'idx_cust_biz_phone');
        $this->addIndexSafely('customers', ['business_id', 'code'], 'idx_cust_biz_code');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropIndexSafely('payables', 'idx_pay_biz_stat_due');
        $this->dropIndexSafely('payables', 'idx_pay_out_stat');
        $this->dropIndexSafely('payables', 'idx_pay_supp_name');
        $this->dropIndexSafely('payable_payments', 'idx_paypmt_pay_id');
        $this->dropIndexSafely('payable_payments', 'idx_paypmt_date');

        $this->dropIndexSafely('transfers', 'idx_trf_biz_stat_date');
        $this->dropIndexSafely('transfers', 'idx_trf_src_dest');
        $this->dropIndexSafely('transfer_items', 'idx_trfitm_trf_ing');

        $this->dropIndexSafely('stock_movements', 'idx_mov_ing_out_date');
        $this->dropIndexSafely('stock_movements', 'idx_mov_biz_out_type');
        $this->dropIndexSafely('stock_movements', 'idx_mov_type_date');
        $this->dropIndexSafely('outlet_ingredients', 'idx_oting_out_ing');

        $this->dropIndexSafely('waste_logs', 'idx_wst_biz_out_date');
        $this->dropIndexSafely('waste_logs', 'idx_wst_ing_id');
        $this->dropIndexSafely('waste_logs', 'idx_wst_menu_id');
        $this->dropIndexSafely('batch_preps', 'idx_prep_biz_out_date');

        $this->dropIndexSafely('cash_transactions', 'idx_cashtx_biz_out_date');
        $this->dropIndexSafely('cash_transactions', 'idx_cashtx_type_date');
        $this->dropIndexSafely('operating_expenses', 'idx_opex_biz_out_date');

        $this->dropIndexSafely('customers', 'idx_cust_biz_phone');
        $this->dropIndexSafely('customers', 'idx_cust_biz_code');
    }
};
