<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Standardize ingredient types:
     * - 'RAW' = Bahan Mentah (Bahan Baku)
     * - 'SEMI_FINISHED' = Bahan Olahan / Batch Prep
     * - 'PERLENGKAPAN' = Perlengkapan, Kemasan & Packaging
     */
    public function up(): void
    {
        if (Schema::hasTable('ingredients')) {
            // Update existing Perlengkapan items to have type = 'PERLENGKAPAN'
            DB::table('ingredients')
                ->where(function ($q) {
                    $q->where('code', 'LIKE', 'PLK-%')
                      ->orWhere('code', 'LIKE', 'PKG-%')
                      ->orWhereRaw('LOWER(category) LIKE ?', ['%perlengkapan%'])
                      ->orWhereRaw('LOWER(category) LIKE ?', ['%packaging%'])
                      ->orWhereRaw('LOWER(category) LIKE ?', ['%kemasan%'])
                      ->orWhereRaw('LOWER(category) LIKE ?', ['%cup%'])
                      ->orWhereRaw('LOWER(category) LIKE ?', ['%sedotan%'])
                      ->orWhereRaw('LOWER(category) LIKE ?', ['%tissue%'])
                      ->orWhereRaw('LOWER(category) LIKE ?', ['%sealer%']);
                })
                ->where('type', '!=', 'SEMI_FINISHED')
                ->update(['type' => 'PERLENGKAPAN']);

            // Ensure null or empty types are set to RAW
            DB::table('ingredients')
                ->whereNull('type')
                ->orWhere('type', '')
                ->update(['type' => 'RAW']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ingredients')) {
            DB::table('ingredients')
                ->where('type', 'PERLENGKAPAN')
                ->update(['type' => 'RAW']);
        }
    }
};
