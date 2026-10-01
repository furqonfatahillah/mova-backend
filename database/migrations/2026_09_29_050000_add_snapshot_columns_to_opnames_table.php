<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opnames', function (Blueprint $table) {
            $table->decimal('stok_awal_periode', 15, 3)->nullable()->after('outlet_id');
            $table->decimal('pembelian', 15, 3)->nullable()->after('stok_awal_periode');
            $table->decimal('pemakaian_teoritis', 15, 3)->nullable()->after('pembelian');
            $table->decimal('prep_usage', 15, 3)->nullable()->after('pemakaian_teoritis');
            $table->decimal('prep_output', 15, 3)->nullable()->after('prep_usage');
            $table->decimal('waste_qty', 15, 3)->nullable()->after('prep_output');
            $table->decimal('waste_value', 15, 2)->nullable()->after('waste_qty');
            $table->decimal('transfer_in', 15, 3)->nullable()->after('waste_value');
            $table->decimal('transfer_out', 15, 3)->nullable()->after('transfer_in');
            $table->decimal('adjustment_qty', 15, 3)->nullable()->after('transfer_out');
            $table->decimal('stok_akhir_teoritis', 15, 3)->nullable()->after('adjustment_qty');
            $table->decimal('cost_per_unit', 15, 2)->nullable()->after('stok_akhir_teoritis');
            $table->decimal('nilai_teoritis', 15, 2)->nullable()->after('cost_per_unit');
            $table->decimal('nilai_aktual', 15, 2)->nullable()->after('actual_qty');
            $table->decimal('variance_qty', 15, 3)->nullable()->after('nilai_aktual');
            $table->decimal('variance_value', 15, 2)->nullable()->after('variance_qty');
            $table->decimal('variance_pct', 8, 2)->nullable()->after('variance_value');
            $table->string('status', 50)->nullable()->after('variance_pct');
        });
    }

    public function down(): void
    {
        Schema::table('opnames', function (Blueprint $table) {
            $table->dropColumn([
                'stok_awal_periode',
                'pembelian',
                'pemakaian_teoritis',
                'prep_usage',
                'prep_output',
                'waste_qty',
                'waste_value',
                'transfer_in',
                'transfer_out',
                'adjustment_qty',
                'stok_akhir_teoritis',
                'cost_per_unit',
                'nilai_teoritis',
                'nilai_aktual',
                'variance_qty',
                'variance_value',
                'variance_pct',
                'status',
            ]);
        });
    }
};
