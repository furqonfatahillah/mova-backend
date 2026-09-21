<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('categories')) {
            $defaultCategories = [
                ['name' => 'Perlengkapan', 'type' => 'INGREDIENT', 'color' => '#00B14F', 'icon' => 'Package', 'sort_order' => 1],
                ['name' => 'Bahan Baku', 'type' => 'INGREDIENT', 'color' => '#3B82F6', 'icon' => 'Layers', 'sort_order' => 2],
                ['name' => 'Bahan Olahan', 'type' => 'INGREDIENT', 'color' => '#8B5CF6', 'icon' => 'ChefHat', 'sort_order' => 3],
                ['name' => 'Dairy & Susu', 'type' => 'INGREDIENT', 'color' => '#F59E0B', 'icon' => 'Coffee', 'sort_order' => 4],
                ['name' => 'Bumbu & Sirup', 'type' => 'INGREDIENT', 'color' => '#EC4899', 'icon' => 'Sparkles', 'sort_order' => 5],
                ['name' => 'Packaging', 'type' => 'INGREDIENT', 'color' => '#06B6D4', 'icon' => 'ShoppingBag', 'sort_order' => 6],
            ];

            $businesses = DB::table('businesses')->pluck('id');
            if ($businesses->isEmpty()) {
                $businesses = collect([1]);
            }

            foreach ($businesses as $bizId) {
                foreach ($defaultCategories as $cat) {
                    $slug = Str::slug($cat['name']);
                    DB::table('categories')->updateOrInsert(
                        [
                            'business_id' => $bizId,
                            'name'        => $cat['name'],
                            'type'        => $cat['type'],
                        ],
                        [
                            'slug'       => $slug,
                            'color'      => $cat['color'],
                            'icon'       => $cat['icon'],
                            'sort_order' => $cat['sort_order'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }

                // Associate existing ingredients with their category_id if currently null
                $catMap = DB::table('categories')
                    ->where('business_id', $bizId)
                    ->pluck('id', 'name');

                foreach ($catMap as $cName => $cId) {
                    DB::table('ingredients')
                        ->where('business_id', $bizId)
                        ->whereNull('category_id')
                        ->whereRaw('LOWER(TRIM(category)) = ?', [strtolower(trim($cName))])
                        ->update(['category_id' => $cId]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Safe rollback
    }
};
