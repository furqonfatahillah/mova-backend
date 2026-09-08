<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('parent_order_number', 50)->nullable()->index()->after('order_number');
            $table->integer('split_index')->nullable()->after('parent_order_number');
            $table->integer('split_total')->nullable()->after('split_index');
            $table->enum('split_type', ['BY_ITEM', 'EQUAL', 'CUSTOM'])->nullable()->after('split_total');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['parent_order_number', 'split_index', 'split_total', 'split_type']);
        });
    }
};
