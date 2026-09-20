<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_items', function (Blueprint $table) {
            if (!Schema::hasColumn('transfer_items', 'unit_price')) {
                $table->decimal('unit_price', 15, 2)->nullable()->after('input_unit');
            }
            if (!Schema::hasColumn('transfer_items', 'total_price')) {
                $table->decimal('total_price', 15, 2)->nullable()->after('unit_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfer_items', function (Blueprint $table) {
            if (Schema::hasColumn('transfer_items', 'total_price')) {
                $table->dropColumn('total_price');
            }
            if (Schema::hasColumn('transfer_items', 'unit_price')) {
                $table->dropColumn('unit_price');
            }
        });
    }
};
