<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_hpp_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable()->index();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->date('date')->index();
            $table->decimal('hpp_before', 15, 2)->default(0);
            $table->decimal('hpp_after', 15, 2)->default(0);
            $table->decimal('diff', 15, 2)->default(0);
            $table->decimal('percentage_change', 8, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->decimal('margin_before_pct', 8, 2)->default(0);
            $table->decimal('margin_after_pct', 8, 2)->default(0);
            $table->string('trigger_type', 50)->default('PURCHASE_RESTOCK')->index();
            $table->foreignId('ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();
            $table->string('ingredient_name', 255)->nullable();
            $table->decimal('ingredient_cost_before', 15, 4)->nullable();
            $table->decimal('ingredient_cost_after', 15, 4)->nullable();
            $table->decimal('portion_qty', 15, 3)->nullable();
            $table->string('portion_unit', 30)->nullable();
            $table->decimal('portion_cost_impact', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['menu_id', 'outlet_id', 'date']);
            $table->index(['business_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_hpp_histories');
    }
};
