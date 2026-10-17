<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('sequence_key', 40); // e.g. "TRX-20261015"
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'sequence_key'], 'uq_order_sequences_biz_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sequences');
    }
};
