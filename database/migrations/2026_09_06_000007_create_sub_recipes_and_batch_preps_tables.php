<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add type and yield details to ingredients
        Schema::table('ingredients', function (Blueprint $table) {
            if (!Schema::hasColumn('ingredients', 'type')) {
                $table->string('type', 20)->default('RAW')->after('category')->comment('RAW = Bahan Mentah, SEMI_FINISHED = Olahan / Prep Good');
            }
            if (!Schema::hasColumn('ingredients', 'yield_qty')) {
                $table->decimal('yield_qty', 15, 3)->nullable()->after('tolerance')->comment('Porsi / kuantitas hasil produksi standar per batch');
            }
            if (!Schema::hasColumn('ingredients', 'yield_unit')) {
                $table->string('yield_unit', 20)->nullable()->after('yield_qty')->comment('Satuan hasil produksi (e.g. potong, kg, porsi)');
            }
        });

        // 2. Create prep_recipes table (sub-recipes for semi-finished goods)
        if (!Schema::hasTable('prep_recipes')) {
            Schema::create('prep_recipes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
                $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete()->comment('Bahan olahan / setengah jadi yang dihasilkan');
                $table->string('name')->comment('Nama racikan / sub-resep standar batch');
                $table->decimal('output_qty', 15, 3)->default(1)->comment('Jumlah output standar per 1 batch');
                $table->string('output_unit', 20)->comment('Satuan output standar (potong, kg, porsi, dsb)');
                $table->text('notes')->nullable()->comment('Instruksi SOP racik / masak dapur');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // 3. Create prep_recipe_items table (raw ingredients needed for 1 batch)
        if (!Schema::hasTable('prep_recipe_items')) {
            Schema::create('prep_recipe_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('prep_recipe_id')->constrained('prep_recipes')->cascadeOnDelete();
                $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete()->comment('Bahan mentah yang dikonsumsi');
                $table->decimal('qty', 15, 3)->comment('Kebutuhan per 1 batch');
                $table->string('unit', 20)->default('gram');
                $table->decimal('waste_std', 5, 2)->default(0)->comment('Standar susut / trimming %');
                $table->timestamps();
            });
        }

        // 4. Create batch_preps table (cooking / production execution logs)
        if (!Schema::hasTable('batch_preps')) {
            Schema::create('batch_preps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
                $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->string('batch_no', 50)->unique();
                $table->foreignId('prep_recipe_id')->nullable()->constrained('prep_recipes')->nullOnDelete();
                $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete()->comment('Bahan olahan yang dimasak');
                $table->decimal('batch_multiplier', 10, 2)->default(1.00)->comment('Faktor pengali batch (misal: 1, 2, 0.5)');
                $table->decimal('expected_output_qty', 15, 3);
                $table->decimal('actual_output_qty', 15, 3);
                $table->string('output_unit', 20);
                $table->decimal('total_cost', 15, 2)->default(0)->comment('Total HPP bahan mentah terpakai');
                $table->decimal('unit_cost', 15, 4)->default(0)->comment('HPP per unit olahan (total_cost / actual_output_qty)');
                $table->date('date');
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('COMPLETED');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('Koki / operator pelaksana');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        // 5. Update stock_movements table for batch_prep_id and type flexibility
        Schema::table('stock_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_movements', 'batch_prep_id')) {
                $table->foreignId('batch_prep_id')->nullable()->after('transfer_id')->constrained('batch_preps')->nullOnDelete();
            }
        });

        // Change stock_movements.type from enum to varchar(30) to accommodate PREP_USAGE and PREP_OUTPUT safely
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE stock_movements MODIFY COLUMN type VARCHAR(30) NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_prep_id');
        });

        Schema::dropIfExists('batch_preps');
        Schema::dropIfExists('prep_recipe_items');
        Schema::dropIfExists('prep_recipes');

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['type', 'yield_qty', 'yield_unit']);
        });
    }
};
