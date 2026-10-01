<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            if (!Schema::hasColumn('receivables', 'shift_id')) {
                $table->foreignId('shift_id')->nullable()->after('transaction_id')->constrained('shifts')->nullOnDelete();
            }
        });

        // Backfill shift_id from linked transactions
        try {
            DB::statement("
                UPDATE receivables r
                INNER JOIN transactions t ON r.transaction_id = t.id
                SET r.shift_id = t.shift_id
                WHERE r.shift_id IS NULL AND t.shift_id IS NOT NULL
            ");
        } catch (\Throwable $e) {
            // Log or ignore if statement fails
        }
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            if (Schema::hasColumn('receivables', 'shift_id')) {
                $table->dropConstrainedForeignId('shift_id');
            }
        });
    }
};
