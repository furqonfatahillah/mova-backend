<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('shifts', 'client_shift_id')) {
                $table->string('client_shift_id', 100)->nullable()->after('shift_name')->index();
                $table->index(['business_id', 'client_shift_id'], 'idx_shifts_biz_client_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'client_shift_id')) {
                $table->dropIndex('idx_shifts_biz_client_id');
                $table->dropColumn('client_shift_id');
            }
        });
    }
};
