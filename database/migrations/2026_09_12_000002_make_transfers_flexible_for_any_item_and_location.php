<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Modifikasi tabel transfers
        Schema::table('transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('transfers', 'source_type')) {
                $table->string('source_type', 30)->default('OUTLET')->after('date'); // OUTLET, WAREHOUSE, EXTERNAL
            }
            if (!Schema::hasColumn('transfers', 'source_name')) {
                $table->string('source_name', 150)->nullable()->after('source_type');
            }
            if (!Schema::hasColumn('transfers', 'destination_type')) {
                $table->string('destination_type', 30)->default('OUTLET')->after('source_outlet_id');
            }
            if (!Schema::hasColumn('transfers', 'destination_name')) {
                $table->string('destination_name', 150)->nullable()->after('destination_type');
            }
            if (!Schema::hasColumn('transfers', 'transfer_type')) {
                $table->string('transfer_type', 30)->default('INTER_OUTLET')->after('destination_outlet_id'); // INTER_OUTLET, INBOUND, OUTBOUND, EXTERNAL
            }
        });

        // Buat source_outlet_id dan destination_outlet_id nullable
        try {
            DB::statement('ALTER TABLE `transfers` MODIFY `source_outlet_id` BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE `transfers` MODIFY `destination_outlet_id` BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->unsignedBigInteger('source_outlet_id')->nullable()->change();
                $table->unsignedBigInteger('destination_outlet_id')->nullable()->change();
            });
        }

        // 2. Modifikasi tabel transfer_items
        Schema::table('transfer_items', function (Blueprint $table) {
            if (!Schema::hasColumn('transfer_items', 'item_type')) {
                $table->string('item_type', 30)->default('INGREDIENT')->after('transfer_id'); // INGREDIENT, PRODUCT
            }
            if (!Schema::hasColumn('transfer_items', 'menu_id')) {
                $table->foreignId('menu_id')->nullable()->after('item_type')->constrained('menus')->nullOnDelete();
            }
        });

        // Buat ingredient_id nullable
        try {
            DB::statement('ALTER TABLE `transfer_items` MODIFY `ingredient_id` BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
            Schema::table('transfer_items', function (Blueprint $table) {
                $table->unsignedBigInteger('ingredient_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('transfer_items', function (Blueprint $table) {
            if (Schema::hasColumn('transfer_items', 'menu_id')) {
                $table->dropForeign(['menu_id']);
                $table->dropColumn(['menu_id']);
            }
            if (Schema::hasColumn('transfer_items', 'item_type')) {
                $table->dropColumn(['item_type']);
            }
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn([
                'source_type',
                'source_name',
                'destination_type',
                'destination_name',
                'transfer_type',
            ]);
        });
    }
};
