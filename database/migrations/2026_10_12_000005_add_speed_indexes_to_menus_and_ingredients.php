<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('menus')) {
            $existing = collect(DB::select("SHOW INDEX FROM `menus`"))->pluck('Key_name')->unique()->toArray();
            Schema::table('menus', function (Blueprint $table) use ($existing) {
                if (!in_array('idx_menus_biz_active', $existing)) {
                    $table->index(['business_id', 'active'], 'idx_menus_biz_active');
                }
                if (!in_array('idx_menus_active', $existing)) {
                    $table->index('active', 'idx_menus_active');
                }
                if (!in_array('idx_menus_cat', $existing)) {
                    $table->index('category', 'idx_menus_cat');
                }
                if (!in_array('idx_menus_item_type', $existing)) {
                    $table->index('item_type', 'idx_menus_item_type');
                }
            });
        }

        if (Schema::hasTable('ingredients')) {
            $existing = collect(DB::select("SHOW INDEX FROM `ingredients`"))->pluck('Key_name')->unique()->toArray();
            Schema::table('ingredients', function (Blueprint $table) use ($existing) {
                if (!in_array('idx_ings_biz_cat', $existing)) {
                    $table->index(['business_id', 'category'], 'idx_ings_biz_cat');
                }
                if (!in_array('idx_ings_category', $existing)) {
                    $table->index('category', 'idx_ings_category');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('menus')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->dropIndex('idx_menus_biz_active');
                $table->dropIndex('idx_menus_active');
                $table->dropIndex('idx_menus_cat');
                $table->dropIndex('idx_menus_item_type');
            });
        }

        if (Schema::hasTable('ingredients')) {
            Schema::table('ingredients', function (Blueprint $table) {
                $table->dropIndex('idx_ings_biz_cat');
                $table->dropIndex('idx_ings_category');
            });
        }
    }
};
