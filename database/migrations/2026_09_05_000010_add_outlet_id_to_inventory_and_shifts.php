<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add outlet_id to stock_movements
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('ingredient_id')->constrained('outlets')->restrictOnDelete();
        });

        // Backfill existing movements to outlet 1 (Pusat / Main)
        $mainOutletId = DB::table('outlets')->where('is_main', true)->value('id') ?? 1;
        DB::table('stock_movements')->update(['outlet_id' => $mainOutletId]);

        // Make outlet_id non-nullable if desired, or keep constrained
        // 2. Add outlet_id to opnames
        Schema::table('opnames', function (Blueprint $table) {
            // Drop old unique constraint first
            $table->dropUnique('opname_unique');
            $table->foreignId('outlet_id')->nullable()->after('ingredient_id')->constrained('outlets')->cascadeOnDelete();
        });

        DB::table('opnames')->update(['outlet_id' => $mainOutletId]);

        Schema::table('opnames', function (Blueprint $table) {
            $table->unique(['period_from', 'period_to', 'ingredient_id', 'outlet_id'], 'opname_outlet_unique');
        });

        // 3. Create outlet_ingredients for isolated initial stock & par level per branch
        Schema::create('outlet_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('stok_awal', 15, 3)->default(0);
            $table->decimal('stok_min', 15, 3)->default(0);
            $table->timestamps();

            $table->unique(['outlet_id', 'ingredient_id']);
        });

        // Seed initial outlet_ingredients for all existing outlets and ingredients
        $outlets = DB::table('outlets')->get();
        $ingredients = DB::table('ingredients')->get();

        $rows = [];
        $now = now();
        foreach ($outlets as $outlet) {
            foreach ($ingredients as $ing) {
                // If main outlet, copy master stok_awal and stok_min; otherwise start with 0
                $stokAwal = $outlet->is_main ? (float) $ing->stok_awal : 0;
                $stokMin  = (float) $ing->stok_min;

                $rows[] = [
                    'outlet_id'     => $outlet->id,
                    'ingredient_id' => $ing->id,
                    'stok_awal'     => $stokAwal,
                    'stok_min'      => $stokMin,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }
        }
        if (!empty($rows)) {
            DB::table('outlet_ingredients')->insert($rows);
        }

        // 4. Add outlet_id to shifts
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('outlet_id')->nullable()->after('user_id')->constrained('outlets')->nullOnDelete();
        });

        // Backfill shifts based on users.outlet_id
        $users = DB::table('users')->whereNotNull('outlet_id')->pluck('outlet_id', 'id');
        foreach ($users as $userId => $outId) {
            DB::table('shifts')->where('user_id', $userId)->update(['outlet_id' => $outId]);
        }
        DB::table('shifts')->whereNull('outlet_id')->update(['outlet_id' => $mainOutletId]);
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['outlet_id']);
            $table->dropColumn('outlet_id');
        });

        Schema::dropIfExists('outlet_ingredients');

        Schema::table('opnames', function (Blueprint $table) {
            $table->dropUnique('opname_outlet_unique');
            $table->dropForeign(['outlet_id']);
            $table->dropColumn('outlet_id');
            $table->unique(['period_from', 'period_to', 'ingredient_id'], 'opname_unique');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['outlet_id']);
            $table->dropColumn('outlet_id');
        });
    }
};
