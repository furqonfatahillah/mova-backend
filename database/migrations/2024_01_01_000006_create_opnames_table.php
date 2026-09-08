<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opnames', function (Blueprint $table) {
            $table->id();
            $table->date('period_from');
            $table->date('period_to');
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('actual_qty', 15, 3)->nullable();
            $table->string('reason')->nullable();
            $table->string('approver')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['period_from', 'period_to', 'ingredient_id'], 'opname_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opnames');
    }
};
