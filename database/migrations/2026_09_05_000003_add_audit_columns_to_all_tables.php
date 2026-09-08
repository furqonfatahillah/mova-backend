<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tables = [
            'ingredients',
            'menus',
            'recipes',
            'transactions',
            'stock_movements',
            'opnames',
            'shifts',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'created_by')) {
                    $t->foreignId('created_by')->nullable()->after('id')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn($table, 'updated_by')) {
                    $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }

        // Backfill existing data
        $firstUser = DB::table('users')->first();
        $adminId = $firstUser?->id ?? 1;

        // Transactions: created_by = user_id
        DB::statement("UPDATE transactions SET created_by = user_id WHERE user_id IS NOT NULL AND created_by IS NULL");
        
        // Stock movements: created_by = user_id
        DB::statement("UPDATE stock_movements SET created_by = user_id WHERE user_id IS NOT NULL AND created_by IS NULL");

        // Opnames: created_by = user_id
        DB::statement("UPDATE opnames SET created_by = user_id WHERE user_id IS NOT NULL AND created_by IS NULL");

        // Shifts: created_by = user_id, updated_by = closed_by
        DB::statement("UPDATE shifts SET created_by = user_id WHERE user_id IS NOT NULL AND created_by IS NULL");
        DB::statement("UPDATE shifts SET updated_by = closed_by WHERE closed_by IS NOT NULL AND updated_by IS NULL");

        // Ingredients, Menus, Recipes: set created_by to admin user if null
        DB::statement("UPDATE ingredients SET created_by = {$adminId} WHERE created_by IS NULL");
        DB::statement("UPDATE menus SET created_by = {$adminId} WHERE created_by IS NULL");
        DB::statement("UPDATE recipes SET created_by = {$adminId} WHERE created_by IS NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'ingredients',
            'menus',
            'recipes',
            'transactions',
            'stock_movements',
            'opnames',
            'shifts',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'updated_by')) {
                    $t->dropForeign([$table . '_updated_by_foreign']);
                    $t->dropColumn('updated_by');
                }
                if (Schema::hasColumn($table, 'created_by')) {
                    $t->dropForeign([$table . '_created_by_foreign']);
                    $t->dropColumn('created_by');
                }
            });
        }
    }
};
