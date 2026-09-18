<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create Master Categories Table
        if (!Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
                $table->string('name', 100);
                $table->string('slug', 120)->nullable();
                $table->string('type', 30)->default('GENERAL')->comment('MENU, INGREDIENT, GENERAL');
                $table->string('color', 20)->nullable();
                $table->string('icon', 50)->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index(['business_id', 'type'], 'idx_cat_biz_type');
            });
        }

        // 2. Create Master Units Table
        if (!Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
                $table->string('name', 50);
                $table->string('symbol', 20);
                $table->boolean('is_base_unit')->default(false);
                $table->timestamps();

                $table->index(['business_id', 'symbol'], 'idx_unit_biz_sym');
            });
        }

        // 3. Create Master Expense Categories Table
        if (!Schema::hasTable('expense_categories')) {
            Schema::create('expense_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
                $table->string('code', 50);
                $table->string('name', 100);
                $table->string('color', 20)->nullable();
                $table->string('icon', 50)->nullable();
                $table->timestamps();

                $table->index(['business_id', 'code'], 'idx_exp_cat_biz_code');
            });
        }

        // 4. Create Master Payment Methods Table
        if (!Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
                $table->string('code', 50);
                $table->string('name', 100);
                $table->string('type', 30)->default('CASH')->comment('CASH, QRIS, TRANSFER, DEBIT, OTHER');
                $table->boolean('active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index(['business_id', 'code'], 'idx_pm_biz_code');
            });
        }

        // 5. Add Foreign Key Columns to Core Tables
        if (Schema::hasTable('menus')) {
            Schema::table('menus', function (Blueprint $table) {
                if (!Schema::hasColumn('menus', 'category_id')) {
                    $table->foreignId('category_id')->nullable()->after('category')->constrained('categories')->nullOnDelete();
                }
                if (!Schema::hasColumn('menus', 'unit_id')) {
                    $table->foreignId('unit_id')->nullable()->after('unit')->constrained('units')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('ingredients')) {
            Schema::table('ingredients', function (Blueprint $table) {
                if (!Schema::hasColumn('ingredients', 'category_id')) {
                    $table->foreignId('category_id')->nullable()->after('category')->constrained('categories')->nullOnDelete();
                }
                if (!Schema::hasColumn('ingredients', 'unit_beli_id')) {
                    $table->foreignId('unit_beli_id')->nullable()->after('unit_beli')->constrained('units')->nullOnDelete();
                }
                if (!Schema::hasColumn('ingredients', 'unit_pakai_id')) {
                    $table->foreignId('unit_pakai_id')->nullable()->after('unit_pakai')->constrained('units')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('operating_expenses')) {
            Schema::table('operating_expenses', function (Blueprint $table) {
                if (!Schema::hasColumn('operating_expenses', 'expense_category_id')) {
                    $table->foreignId('expense_category_id')->nullable()->after('category')->constrained('expense_categories')->nullOnDelete();
                }
                if (!Schema::hasColumn('operating_expenses', 'payment_method_id')) {
                    $table->foreignId('payment_method_id')->nullable()->after('payment_method')->constrained('payment_methods')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('transactions', 'payment_method_id')) {
                    $table->foreignId('payment_method_id')->nullable()->after('payment_method')->constrained('payment_methods')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('cash_transactions')) {
            Schema::table('cash_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('cash_transactions', 'expense_category_id')) {
                    $table->foreignId('expense_category_id')->nullable()->after('category')->constrained('expense_categories')->nullOnDelete();
                }
                if (!Schema::hasColumn('cash_transactions', 'payment_method_id')) {
                    $table->foreignId('payment_method_id')->nullable()->after('payment_method')->constrained('payment_methods')->nullOnDelete();
                }
            });
        }

        // ==========================================
        // 6. SEED MASTER DATA & BACKFILL
        // ==========================================

        // A. Seed Units
        $defaultUnits = [
            ['name' => 'Gram', 'symbol' => 'gr', 'is_base_unit' => true],
            ['name' => 'Kilogram', 'symbol' => 'kg', 'is_base_unit' => false],
            ['name' => 'Mililiter', 'symbol' => 'ml', 'is_base_unit' => true],
            ['name' => 'Liter', 'symbol' => 'lt', 'is_base_unit' => false],
            ['name' => 'Pieces / Butir', 'symbol' => 'pcs', 'is_base_unit' => true],
            ['name' => 'Porsi', 'symbol' => 'porsi', 'is_base_unit' => true],
            ['name' => 'Botol', 'symbol' => 'btl', 'is_base_unit' => false],
            ['name' => 'Cup', 'symbol' => 'cup', 'is_base_unit' => false],
            ['name' => 'Dus / Karton', 'symbol' => 'dus', 'is_base_unit' => false],
            ['name' => 'Pack', 'symbol' => 'pack', 'is_base_unit' => false],
            ['name' => 'Sachet', 'symbol' => 'sachet', 'is_base_unit' => false],
            ['name' => 'Kaleng', 'symbol' => 'can', 'is_base_unit' => false],
        ];

        foreach ($defaultUnits as $u) {
            DB::table('units')->updateOrInsert(
                ['symbol' => $u['symbol']],
                [
                    'name'         => $u['name'],
                    'is_base_unit' => $u['is_base_unit'],
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]
            );
        }

        // B. Seed Expense Categories
        $defaultExpenseCategories = [
            ['code' => 'SALARY', 'name' => 'Gaji & Upah Karyawan', 'color' => '#8B5CF6'],
            ['code' => 'UTILITIES', 'name' => 'Listrik, Air & Internet', 'color' => '#3B82F6'],
            ['code' => 'GAS', 'name' => 'Gas LPG & Bahan Bakar', 'color' => '#F59E0B'],
            ['code' => 'RENT', 'name' => 'Sewa Tempat & Bangunan', 'color' => '#10B981'],
            ['code' => 'MAINTENANCE', 'name' => 'Perawatan & Perbaikan', 'color' => '#EC4899'],
            ['code' => 'MARKETING', 'name' => 'Pemasaran & Iklan', 'color' => '#6366F1'],
            ['code' => 'LOGISTICS', 'name' => 'Logistik & Pengiriman', 'color' => '#14B8A6'],
            ['code' => 'OTHER', 'name' => 'Beban Operasional Lainnya', 'color' => '#64748B'],
        ];

        foreach ($defaultExpenseCategories as $ec) {
            DB::table('expense_categories')->updateOrInsert(
                ['code' => $ec['code']],
                [
                    'name'       => $ec['name'],
                    'color'      => $ec['color'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // C. Seed Payment Methods
        $defaultPaymentMethods = [
            ['code' => 'CASH', 'name' => 'Tunai (Cash)', 'type' => 'CASH', 'sort_order' => 1],
            ['code' => 'QRIS', 'name' => 'QRIS (GoPay/OVO/ShopeePay/BCA)', 'type' => 'QRIS', 'sort_order' => 2],
            ['code' => 'TRANSFER', 'name' => 'Transfer Bank', 'type' => 'TRANSFER', 'sort_order' => 3],
            ['code' => 'DEBIT', 'name' => 'Kartu Debit / EDC', 'type' => 'DEBIT', 'sort_order' => 4],
            ['code' => 'PETTY_CASH', 'name' => 'Kas Kecil', 'type' => 'CASH', 'sort_order' => 5],
        ];

        foreach ($defaultPaymentMethods as $pm) {
            DB::table('payment_methods')->updateOrInsert(
                ['code' => $pm['code']],
                [
                    'name'       => $pm['name'],
                    'type'       => $pm['type'],
                    'active'     => true,
                    'sort_order' => $pm['sort_order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // D. Backfill Categories from Menus
        $menuCats = DB::table('menus')->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category');
        foreach ($menuCats as $catName) {
            $catId = DB::table('categories')->insertGetId([
                'name'       => trim($catName),
                'slug'       => Str::slug(trim($catName)),
                'type'       => 'MENU',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('menus')->where('category', $catName)->update(['category_id' => $catId]);
        }

        // E. Backfill Categories from Ingredients
        $ingCats = DB::table('ingredients')->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category');
        foreach ($ingCats as $catName) {
            $existing = DB::table('categories')->where('name', trim($catName))->where('type', 'INGREDIENT')->first();
            $catId = $existing ? $existing->id : DB::table('categories')->insertGetId([
                'name'       => trim($catName),
                'slug'       => Str::slug(trim($catName)),
                'type'       => 'INGREDIENT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('ingredients')->where('category', $catName)->update(['category_id' => $catId]);
        }

        // F. Backfill Units in Ingredients
        $unitMap = DB::table('units')->pluck('id', 'symbol')->all();
        $unitNameMap = DB::table('units')->pluck('id', 'name')->all();

        $allIngredients = DB::table('ingredients')->select('id', 'unit_beli', 'unit_pakai')->get();
        foreach ($allIngredients as $ing) {
            $beliId = $unitMap[strtolower(trim($ing->unit_beli ?? ''))] ?? $unitNameMap[trim($ing->unit_beli ?? '')] ?? null;
            $pakaiId = $unitMap[strtolower(trim($ing->unit_pakai ?? ''))] ?? $unitNameMap[trim($ing->unit_pakai ?? '')] ?? null;

            if ($beliId || $pakaiId) {
                DB::table('ingredients')->where('id', $ing->id)->update([
                    'unit_beli_id'  => $beliId,
                    'unit_pakai_id' => $pakaiId,
                ]);
            }
        }

        // G. Backfill Expense Categories in Operating Expenses
        $expCatMap = DB::table('expense_categories')->pluck('id', 'code')->all();
        foreach ($expCatMap as $code => $id) {
            DB::table('operating_expenses')->where('category', $code)->update(['expense_category_id' => $id]);
            DB::table('cash_transactions')->where('category', $code)->update(['expense_category_id' => $id]);
        }

        // H. Backfill Payment Methods in Transactions
        $pmMap = DB::table('payment_methods')->pluck('id', 'code')->all();
        foreach ($pmMap as $code => $id) {
            DB::table('transactions')->where('payment_method', $code)->update(['payment_method_id' => $id]);
            DB::table('operating_expenses')->where('payment_method', $code)->update(['payment_method_id' => $id]);
            DB::table('cash_transactions')->where('payment_method', $code)->update(['payment_method_id' => $id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('cash_transactions')) {
            Schema::table('cash_transactions', function (Blueprint $table) {
                if (Schema::hasColumn('cash_transactions', 'expense_category_id')) {
                    $table->dropForeign(['expense_category_id']);
                    $table->dropColumn('expense_category_id');
                }
                if (Schema::hasColumn('cash_transactions', 'payment_method_id')) {
                    $table->dropForeign(['payment_method_id']);
                    $table->dropColumn('payment_method_id');
                }
            });
        }

        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                if (Schema::hasColumn('transactions', 'payment_method_id')) {
                    $table->dropForeign(['payment_method_id']);
                    $table->dropColumn('payment_method_id');
                }
            });
        }

        if (Schema::hasTable('operating_expenses')) {
            Schema::table('operating_expenses', function (Blueprint $table) {
                if (Schema::hasColumn('operating_expenses', 'expense_category_id')) {
                    $table->dropForeign(['expense_category_id']);
                    $table->dropColumn('expense_category_id');
                }
                if (Schema::hasColumn('operating_expenses', 'payment_method_id')) {
                    $table->dropForeign(['payment_method_id']);
                    $table->dropColumn('payment_method_id');
                }
            });
        }

        if (Schema::hasTable('ingredients')) {
            Schema::table('ingredients', function (Blueprint $table) {
                if (Schema::hasColumn('ingredients', 'category_id')) {
                    $table->dropForeign(['category_id']);
                    $table->dropColumn('category_id');
                }
                if (Schema::hasColumn('ingredients', 'unit_beli_id')) {
                    $table->dropForeign(['unit_beli_id']);
                    $table->dropColumn('unit_beli_id');
                }
                if (Schema::hasColumn('ingredients', 'unit_pakai_id')) {
                    $table->dropForeign(['unit_pakai_id']);
                    $table->dropColumn('unit_pakai_id');
                }
            });
        }

        if (Schema::hasTable('menus')) {
            Schema::table('menus', function (Blueprint $table) {
                if (Schema::hasColumn('menus', 'category_id')) {
                    $table->dropForeign(['category_id']);
                    $table->dropColumn('category_id');
                }
                if (Schema::hasColumn('menus', 'unit_id')) {
                    $table->dropForeign(['unit_id']);
                    $table->dropColumn('unit_id');
                }
            });
        }

        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
    }
};
