<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlet_ingredients', function (Blueprint $table) {
            if (!Schema::hasColumn('outlet_ingredients', 'tanggal_saldo_awal')) {
                $table->date('tanggal_saldo_awal')->nullable()->after('saldo_awal_nominal');
            }
        });

        Schema::table('ingredients', function (Blueprint $table) {
            if (!Schema::hasColumn('ingredients', 'tanggal_saldo_awal')) {
                $table->date('tanggal_saldo_awal')->nullable()->after('saldo_awal_nominal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('outlet_ingredients', function (Blueprint $table) {
            if (Schema::hasColumn('outlet_ingredients', 'tanggal_saldo_awal')) {
                $table->dropColumn('tanggal_saldo_awal');
            }
        });

        Schema::table('ingredients', function (Blueprint $table) {
            if (Schema::hasColumn('ingredients', 'tanggal_saldo_awal')) {
                $table->dropColumn('tanggal_saldo_awal');
            }
        });
    }
};
