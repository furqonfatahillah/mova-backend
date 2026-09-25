<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
     * Run the migrations for super fast query execution.
     */
    public function up(): void
    {
        // 1. Receivables table
        if (Schema::hasTable('receivables')) {
            $this->addIndexSafely('receivables', ['business_id', 'issue_date'], 'idx_rec_biz_issue');
            $this->addIndexSafely('receivables', ['outlet_id', 'issue_date'], 'idx_rec_out_issue');
            $this->addIndexSafely('receivables', ['customer_id'], 'idx_rec_cust_id');
        }

        // 2. Point redemptions table
        if (Schema::hasTable('point_redemptions')) {
            $this->addIndexSafely('point_redemptions', ['business_id', 'created_at'], 'idx_pred_biz_created');
        }

        // 3. Transactions table
        if (Schema::hasTable('transactions')) {
            $this->addIndexSafely('transactions', ['business_id', 'outlet_id', 'created_at'], 'idx_trx_biz_out_created');
            $this->addIndexSafely('transactions', ['business_id', 'customer_id'], 'idx_trx_biz_cust');
            $this->addIndexSafely('transactions', ['payment_method'], 'idx_trx_pay_method');
        }

        // 4. Menus & Ingredients tables
        if (Schema::hasTable('menus')) {
            $this->addIndexSafely('menus', ['business_id', 'is_active'], 'idx_menu_biz_active');
            $this->addIndexSafely('menus', ['category_id'], 'idx_menu_cat_id');
        }

        if (Schema::hasTable('ingredients')) {
            $this->addIndexSafely('ingredients', ['business_id', 'type'], 'idx_ing_biz_type');
            $this->addIndexSafely('ingredients', ['business_id', 'is_active'], 'idx_ing_biz_active');
        }

        // 5. Discounts table
        if (Schema::hasTable('discounts')) {
            $this->addIndexSafely('discounts', ['business_id', 'is_active'], 'idx_disc_biz_active');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropIndexSafely('receivables', 'idx_rec_biz_issue');
        $this->dropIndexSafely('receivables', 'idx_rec_out_issue');
        $this->dropIndexSafely('receivables', 'idx_rec_cust_id');

        $this->dropIndexSafely('point_redemptions', 'idx_pred_biz_created');

        $this->dropIndexSafely('transactions', 'idx_trx_biz_out_created');
        $this->dropIndexSafely('transactions', 'idx_trx_biz_cust');
        $this->dropIndexSafely('transactions', 'idx_trx_pay_method');

        $this->dropIndexSafely('menus', 'idx_menu_biz_active');
        $this->dropIndexSafely('menus', 'idx_menu_cat_id');

        $this->dropIndexSafely('ingredients', 'idx_ing_biz_type');
        $this->dropIndexSafely('ingredients', 'idx_ing_biz_active');

        $this->dropIndexSafely('discounts', 'idx_disc_biz_active');
    }
};
