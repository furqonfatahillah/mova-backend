<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->onDelete('cascade');
            $table->foreignId('bundled_menu_id')->nullable()->constrained('menus')->onDelete('cascade');
            $table->foreignId('ingredient_id')->nullable()->constrained('ingredients')->onDelete('cascade');
            $table->decimal('qty', 10, 4)->default(1);
            $table->string('unit')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
    }
};
