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
        Schema::table('menus', function (Blueprint $table) {
            if (!Schema::hasColumn('menus', 'estimated_hpp')) {
                $table->decimal('estimated_hpp', 15, 2)->nullable()->default(0)->after('cost_price');
            }
        });

        // Backfill: isi estimated_hpp dari cost_price yang ada saat ini
        try {
            DB::statement("UPDATE menus SET estimated_hpp = cost_price WHERE (estimated_hpp IS NULL OR estimated_hpp = 0) AND cost_price > 0");
            
            // Setel KOPI AREN ke Target Estimasi HPP awal 8000 sesuai permintaan owner/pengguna
            DB::statement("UPDATE menus SET estimated_hpp = 8000.00 WHERE code = 'MNU-001' OR UPPER(name) = 'KOPI AREN'");
        } catch (\Throwable $e) {}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            if (Schema::hasColumn('menus', 'estimated_hpp')) {
                $table->dropColumn('estimated_hpp');
            }
        });
    }
};
