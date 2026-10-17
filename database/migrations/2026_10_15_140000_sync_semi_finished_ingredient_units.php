<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\BatchPrepController;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            BatchPrepController::syncSemiFinishedUnits();
        } catch (\Throwable $e) {
            // Direct DB fallback if classes/autoloading differs during deployment
            $recipes = DB::table('prep_recipes')->get();
            foreach ($recipes as $r) {
                if (!$r->ingredient_id || empty($r->output_unit)) continue;
                $ing = DB::table('ingredients')->where('id', $r->ingredient_id)->first();
                if (!$ing) continue;

                $updates = [
                    'type'       => 'SEMI_FINISHED',
                    'yield_unit' => $r->output_unit,
                    'unit_pakai' => $r->output_unit,
                    'yield_qty'  => $r->output_qty,
                ];

                if (empty($ing->unit_beli) || strtolower((string)$ing->unit_beli) === strtolower((string)$ing->unit_pakai) || (float)$ing->konversi <= 1) {
                    $updates['unit_beli'] = $r->output_unit;
                    $updates['konversi'] = 1;
                }

                DB::table('ingredients')->where('id', $r->ingredient_id)->update($updates);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: Data synchronization migration
    }
};
