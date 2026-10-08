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
        if (Schema::hasTable('accounts')) {
            // Rename 6-61008 to BIAYA LAIN-LAIN
            DB::table('accounts')
                ->where('code', '6-61008')
                ->update([
                    'name'        => 'BIAYA LAIN-LAIN',
                    'category'    => 'OTHER_EXPENSE',
                    'description' => 'Biaya lain-lain dari POS (selisih kurang kasir dan beban non-operasional lainnya)',
                    'updated_at'  => now(),
                ]);

            // Update 4-41002 description
            DB::table('accounts')
                ->where('code', '4-41002')
                ->update([
                    'description' => 'Pendapatan lain-lain dari POS (selisih lebih kasir dan pendapatan non-operasional lainnya)',
                    'updated_at'  => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('accounts')) {
            DB::table('accounts')
                ->where('code', '6-61008')
                ->update([
                    'name'        => 'BIAYA SELISIH PENCATATAN KASIR',
                    'category'    => 'CASH_SHORTAGE',
                    'description' => 'Biaya yang terjadi pada saat kasir closing shift dan terdapat selisih kurang kas dibanding kas yang seharusnya',
                    'updated_at'  => now(),
                ]);
        }
    }
};
