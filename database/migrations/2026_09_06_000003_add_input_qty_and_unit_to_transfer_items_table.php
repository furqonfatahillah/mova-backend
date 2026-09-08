<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_items', function (Blueprint $table) {
            $table->decimal('input_qty', 15, 3)->nullable()->after('qty');
            $table->string('input_unit', 30)->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_items', function (Blueprint $table) {
            $table->dropColumn(['input_qty', 'input_unit']);
        });
    }
};
