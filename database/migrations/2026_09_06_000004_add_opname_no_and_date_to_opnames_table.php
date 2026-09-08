<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opnames', function (Blueprint $table) {
            $table->string('opname_no', 50)->nullable()->after('outlet_id')->index();
            $table->date('opname_date')->nullable()->after('opname_no')->index();
        });

        // Backfill existing records with generated opname_no grouped by outlet_id, period_from, period_to
        $sessions = DB::table('opnames')
            ->select('outlet_id', 'period_from', 'period_to')
            ->distinct()
            ->get();

        $counter = 1;
        foreach ($sessions as $s) {
            $dateClean = str_replace('-', '', $s->period_to);
            $opnameNo = 'OPN-' . $dateClean . '-' . str_pad($counter, 4, '0', STR_PAD_LEFT);
            DB::table('opnames')
                ->where('outlet_id', $s->outlet_id)
                ->where('period_from', $s->period_from)
                ->where('period_to', $s->period_to)
                ->update([
                    'opname_no'   => $opnameNo,
                    'opname_date' => $s->period_to,
                ]);
            $counter++;
        }
    }

    public function down(): void
    {
        Schema::table('opnames', function (Blueprint $table) {
            $table->dropIndex(['opname_no']);
            $table->dropIndex(['opname_date']);
            $table->dropColumn(['opname_no', 'opname_date']);
        });
    }
};
