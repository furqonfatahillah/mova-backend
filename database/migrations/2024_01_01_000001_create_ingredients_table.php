<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('category')->default('Umum');
            $table->string('unit_beli', 20)->default('Kg');
            $table->string('unit_pakai', 20)->default('gram');
            $table->decimal('konversi', 10, 2)->default(1000);
            $table->decimal('harga', 15, 2)->default(0);
            $table->decimal('stok_awal', 15, 3)->default(0);
            $table->decimal('stok_min', 15, 3)->default(0);
            $table->decimal('tolerance', 5, 2)->default(5);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
