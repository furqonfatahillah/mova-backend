<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlet_ingredients', function (Blueprint $table) {
            if (!Schema::hasColumn('outlet_ingredients', 'saldo_awal_nominal')) {
                $table->decimal('saldo_awal_nominal', 18, 2)->default(0)->after('stok_awal');
            }
        });

        Schema::table('ingredients', function (Blueprint $table) {
            if (!Schema::hasColumn('ingredients', 'saldo_awal_nominal')) {
                $table->decimal('saldo_awal_nominal', 18, 2)->default(0)->after('stok_awal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('outlet_ingredients', function (Blueprint $table) {
            if (Schema::hasColumn('outlet_ingredients', 'saldo_awal_nominal')) {
                $table->dropColumn('saldo_awal_nominal');
            }
        });

        Schema::table('ingredients', function (Blueprint $table) {
            if (Schema::hasColumn('ingredients', 'saldo_awal_nominal')) {
                $table->dropColumn('saldo_awal_nominal');
            }
        });
    }
};
