<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operating_expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('operating_expenses', 'shift_id')) {
                $table->foreignId('shift_id')->nullable()->after('outlet_id')->constrained('shifts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('operating_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('operating_expenses', 'shift_id')) {
                $table->dropForeign(['shift_id']);
                $table->dropColumn('shift_id');
            }
        });
    }
};
