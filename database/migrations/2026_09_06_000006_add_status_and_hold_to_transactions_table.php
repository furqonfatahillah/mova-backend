<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('status', 30)->default('PAID')->index()->after('order_number');
            $table->index(['outlet_id', 'status']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['outlet_id', 'status']);
            $table->dropIndex(['business_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
