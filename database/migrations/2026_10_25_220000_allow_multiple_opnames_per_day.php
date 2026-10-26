<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasIndex('opnames', 'opname_outlet_unique')) {
            Schema::table('opnames', function (Blueprint $table) {
                $table->dropUnique('opname_outlet_unique');
            });
        }

        // Ensure any existing opname rows have a valid opname_no before creating session unique index
        $nullOpnames = DB::table('opnames')->whereNull('opname_no')->orWhere('opname_no', '')->get();
        foreach ($nullOpnames as $row) {
            $dateClean = str_replace('-', '', $row->opname_date ?: ($row->period_to ?: now()->toDateString()));
            $generatedNo = 'OPN-' . $dateClean . '-' . str_pad($row->id, 4, '0', STR_PAD_LEFT);
            DB::table('opnames')->where('id', $row->id)->update(['opname_no' => $generatedNo]);
        }

        if (!Schema::hasIndex('opnames', 'opname_session_ingredient_unique')) {
            Schema::table('opnames', function (Blueprint $table) {
                $table->unique(['opname_no', 'ingredient_id', 'outlet_id'], 'opname_session_ingredient_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('opnames', 'opname_session_ingredient_unique')) {
            Schema::table('opnames', function (Blueprint $table) {
                $table->dropUnique('opname_session_ingredient_unique');
            });
        }

        if (!Schema::hasIndex('opnames', 'opname_outlet_unique')) {
            Schema::table('opnames', function (Blueprint $table) {
                $table->unique(['period_from', 'period_to', 'ingredient_id', 'outlet_id'], 'opname_outlet_unique');
            });
        }
    }
};
