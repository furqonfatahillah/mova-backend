<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('receivable_payments', 'shift_id')) {
                $table->foreignId('shift_id')->nullable()->after('outlet_id')->constrained('shifts')->nullOnDelete();
            }
        });

        // 1. Backfill shift_id from linked parent receivable if receivable has shift_id
        try {
            DB::statement("
                UPDATE receivable_payments rp
                INNER JOIN receivables r ON rp.receivable_id = r.id
                SET rp.shift_id = r.shift_id
                WHERE rp.shift_id IS NULL AND r.shift_id IS NOT NULL
            ");
        } catch (\Throwable $e) {
            // ignore if failed
        }

        // 2. Backfill shift_id based on payment timestamp within shift period of same outlet
        try {
            DB::statement("
                UPDATE receivable_payments rp
                INNER JOIN shifts s ON rp.outlet_id = s.outlet_id
                    AND rp.created_at >= s.opened_at
                    AND (rp.created_at <= s.closed_at OR s.closed_at IS NULL)
                SET rp.shift_id = s.id
                WHERE rp.shift_id IS NULL
            ");
        } catch (\Throwable $e) {
            // ignore if failed
        }
    }

    public function down(): void
    {
        Schema::table('receivable_payments', function (Blueprint $table) {
            if (Schema::hasColumn('receivable_payments', 'shift_id')) {
                $table->dropConstrainedForeignId('shift_id');
            }
        });
    }
};
