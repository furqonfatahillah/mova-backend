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
        // Delete all SHIFT_CLOSING entries from journal_entries so selisih kasir does not deduct Kas Kecil Kasir in Neraca
        if (Schema::hasTable('journal_entries')) {
            $shiftClosingIds = DB::table('journal_entries')
                ->where('reference_type', 'SHIFT_CLOSING')
                ->pluck('id');

            if ($shiftClosingIds->isNotEmpty()) {
                if (Schema::hasTable('journal_lines')) {
                    DB::table('journal_lines')
                        ->whereIn('journal_entry_id', $shiftClosingIds)
                        ->delete();
                }

                DB::table('journal_entries')
                    ->whereIn('id', $shiftClosingIds)
                    ->delete();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse needed
    }
};
