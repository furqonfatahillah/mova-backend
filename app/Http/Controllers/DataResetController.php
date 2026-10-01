<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataResetController extends Controller
{
    /**
     * List of all transaction & operational tables
     */
    protected $transactionTables = [
        'transaction_modifiers',
        'transactions',
        'point_redemptions',
        'shift_schedules',
        'shifts',
        'stock_movements',
        'urgent_notes',
        'waste_logs',
        'opnames',
        'transfer_items',
        'transfers',
        'batch_preps',
        'payable_payments',
        'payables',
        'receivable_payments',
        'receivables',
        'operating_expenses',
        'cash_transactions',
        'coin_transactions',
    ];

    /**
     * List of master data tables (Menu, Bahan, Resep, Modifiers, Customers, etc.)
     */
    protected $masterTables = [
        'bundle_items',
        'recipe_items',
        'recipes',
        'prep_recipe_items',
        'prep_recipes',
        'menu_hpp_histories',
        'menu_modifier_groups',
        'modifier_options',
        'modifier_groups',
        'outlet_menus',
        'menus',
        'outlet_ingredients',
        'ingredients',
        'customers',
        'discounts',
        'suppliers',
        'bank_accounts',
        'payment_gateway_settings',
    ];

    /**
     * Get preview stats before resetting
     */
    public function preview(Request $request)
    {
        $stats = [
            'transactions_count' => Schema::hasTable('transactions') ? DB::table('transactions')->count() : 0,
            'shifts_count'       => Schema::hasTable('shifts') ? DB::table('shifts')->count() : 0,
            'stock_movements_count' => Schema::hasTable('stock_movements') ? DB::table('stock_movements')->count() : 0,
            'menus_count'        => Schema::hasTable('menus') ? DB::table('menus')->count() : 0,
            'ingredients_count'  => Schema::hasTable('ingredients') ? DB::table('ingredients')->count() : 0,
            'customers_count'    => Schema::hasTable('customers') ? DB::table('customers')->count() : 0,
            'expenses_count'     => Schema::hasTable('operating_expenses') ? DB::table('operating_expenses')->count() : 0,
            'receivables_count'  => Schema::hasTable('receivables') ? DB::table('receivables')->count() : 0,
            'payables_count'     => Schema::hasTable('payables') ? DB::table('payables')->count() : 0,
            'transfers_count'    => Schema::hasTable('transfers') ? DB::table('transfers')->count() : 0,
            'opnames_count'      => Schema::hasTable('opnames') ? DB::table('opnames')->count() : 0,
            'urgent_notes_count' => Schema::hasTable('urgent_notes') ? DB::table('urgent_notes')->count() : 0,
            'batch_preps_count'  => Schema::hasTable('batch_preps') ? DB::table('batch_preps')->count() : 0,
            // Preserved
            'users_count'        => Schema::hasTable('users') ? DB::table('users')->count() : 0,
            'outlets_count'      => Schema::hasTable('outlets') ? DB::table('outlets')->count() : 0,
            'businesses_count'   => Schema::hasTable('businesses') ? DB::table('businesses')->count() : 0,
        ];

        return response()->json([
            'success' => true,
            'stats'   => $stats,
        ]);
    }

    /**
     * Execute reset
     * @param Request $request
     * - mode: 'all' (default: reset all data except users and outlets) or 'transactions_only'
     */
    public function resetData(Request $request)
    {
        $mode = $request->input('mode', 'all'); // 'all' or 'transactions_only'

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            $clearedTables = [];
            $totalDeleted = 0;

            $tablesToClear = $this->transactionTables;
            if ($mode === 'all') {
                $tablesToClear = array_merge($tablesToClear, $this->masterTables);
            }

            foreach ($tablesToClear as $table) {
                if (Schema::hasTable($table)) {
                    $count = DB::table($table)->count();
                    DB::table($table)->truncate();
                    $clearedTables[] = [
                        'table' => $table,
                        'count' => $count,
                    ];
                    $totalDeleted += $count;
                }
            }

            // If mode is 'transactions_only', reset current stock numbers on outlet_ingredients to 0
            if ($mode === 'transactions_only') {
                if (Schema::hasTable('outlet_ingredients')) {
                    DB::table('outlet_ingredients')->update(['current_stock' => 0]);
                }
                if (Schema::hasTable('ingredients')) {
                    DB::table('ingredients')->update(['current_stock' => 0]);
                }
            }

            // Ensure lookup tables have default values if mode is 'all'
            if ($mode === 'all') {
                $this->seedDefaults();
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            return response()->json([
                'success'        => true,
                'message'        => $mode === 'all'
                    ? 'Seluruh data operasional & master (menu, resep, bahan, transaksi) berhasil di-reset! Akun Pengguna (Users) dan Cabang Toko (Outlets) tetap AMAN.'
                    : 'Seluruh riwayat transaksi & mutasi stok berhasil dibersihkan! Master Menu, Bahan, dan Resep tetap tersimpan.',
                'mode'           => $mode,
                'total_deleted'  => $totalDeleted,
                'cleared_tables' => $clearedTables,
                'preserved'      => [
                    'users'      => Schema::hasTable('users') ? DB::table('users')->count() : 0,
                    'outlets'    => Schema::hasTable('outlets') ? DB::table('outlets')->count() : 0,
                    'businesses' => Schema::hasTable('businesses') ? DB::table('businesses')->count() : 0,
                ],
            ]);
        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            return response()->json([
                'success' => false,
                'message' => 'Gagal melakukan reset data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore default categories, units, and payment methods if needed
     */
    protected function seedDefaults()
    {
        // Re-seed default payment methods if empty
        if (Schema::hasTable('payment_methods') && DB::table('payment_methods')->count() === 0) {
            $defaultMethods = [
                ['name' => 'Tunai (Cash)', 'code' => 'CASH', 'type' => 'CASH', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'QRIS', 'code' => 'QRIS', 'type' => 'QRIS', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Transfer Bank', 'code' => 'TRANSFER', 'type' => 'TRANSFER', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Debit Card', 'code' => 'DEBIT', 'type' => 'CARD', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Credit Card', 'code' => 'CREDIT', 'type' => 'CARD', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'GrabFood', 'code' => 'GRAB', 'type' => 'MERCHANT', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'GoFood', 'code' => 'GOFOOD', 'type' => 'MERCHANT', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'ShopeeFood', 'code' => 'SHOPEEFOOD', 'type' => 'MERCHANT', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ];
            foreach ($defaultMethods as $dm) {
                DB::table('payment_methods')->insert($dm);
            }
        }

        // Re-seed default units if empty
        if (Schema::hasTable('units') && DB::table('units')->count() === 0) {
            $defaultUnits = [
                ['name' => 'Gram', 'symbol' => 'gram', 'is_base_unit' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Kilogram', 'symbol' => 'kg', 'is_base_unit' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Mililiter', 'symbol' => 'ml', 'is_base_unit' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Liter', 'symbol' => 'liter', 'is_base_unit' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Pcs / Buah', 'symbol' => 'pcs', 'is_base_unit' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Pack / Bungkus', 'symbol' => 'pack', 'is_base_unit' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Botol', 'symbol' => 'botol', 'is_base_unit' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Kaleng', 'symbol' => 'can', 'is_base_unit' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Dus / Box', 'symbol' => 'dus', 'is_base_unit' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Porsi', 'symbol' => 'porsi', 'is_base_unit' => 1, 'created_at' => now(), 'updated_at' => now()],
            ];
            foreach ($defaultUnits as $du) {
                DB::table('units')->insert($du);
            }
        }
    }
}
