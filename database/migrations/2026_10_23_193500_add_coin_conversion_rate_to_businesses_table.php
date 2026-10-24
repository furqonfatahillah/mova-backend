<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('businesses') && !Schema::hasColumn('businesses', 'coin_conversion_rate')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->decimal('coin_conversion_rate', 10, 2)->nullable()->after('is_unlimited_coins')
                    ->comment('Tarif konversi koin top up khusus per bisnis. Null = ikuti default platform');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('businesses') && Schema::hasColumn('businesses', 'coin_conversion_rate')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropColumn('coin_conversion_rate');
            });
        }
    }
};
