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
        // 1. If any journal lines were recorded with 1-12002, re-map them to 1-12001
        if (Schema::hasTable('journal_lines')) {
            DB::table('journal_lines')
                ->where('account_code', '1-12002')
                ->update(['account_code' => '1-12001']);
        }

        // 2. Remove 1-12002 (PIUTANG KARYAWAN) from accounts table
        if (Schema::hasTable('accounts')) {
            DB::table('accounts')
                ->where('code', '1-12002')
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-seed 1-12002 if rolled back
        if (Schema::hasTable('accounts')) {
            $bizIds = DB::table('businesses')->pluck('id');
            foreach ($bizIds as $bizId) {
                DB::table('accounts')->insertOrIgnore([
                    'business_id'    => $bizId,
                    'code'           => '1-12002',
                    'name'           => 'PIUTANG KARYAWAN',
                    'type'           => 'ASSET',
                    'category'       => 'RECEIVABLE',
                    'normal_balance' => 'DEBIT',
                    'description'    => 'Pengeluaran yang terjadi saat ada karyawan yang kasbon',
                    'is_system'      => true,
                    'is_active'      => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }
};
