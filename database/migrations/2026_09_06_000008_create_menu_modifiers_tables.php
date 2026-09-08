<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Kelompok Varian / Modifier (e.g. Level Pedas, Pilihan Saus, Ukuran, Extra Topping)
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();
            $table->string('name')->comment('Nama kelompok modifier (e.g. Level Pedas, Extra Topping)');
            $table->string('selection_type', 20)->default('SINGLE')->comment('SINGLE (radio) atau MULTIPLE (checkbox)');
            $table->boolean('is_required')->default(false)->comment('Apakah wajib dipilih salah satu');
            $table->unsignedInteger('min_selection')->default(0);
            $table->unsignedInteger('max_selection')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 2. Opsi Pilihan di dalam Modifier Group (e.g. Level 1, Level 3, Extra Keju +5.000)
        Schema::create('modifier_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->string('name')->comment('Nama pilihan (e.g. Level 3 (Pedas), Extra Keju Mozzarella)');
            $table->decimal('price', 15, 2)->default(0)->comment('Tambahan harga (misal: 0 atau 5000)');
            $table->foreignId('ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete()->comment('Bahan baku/olahan yang dipotong stoknya');
            $table->decimal('qty', 15, 3)->default(0)->comment('Banyaknya bahan baku yang dipotong per porsi opsi');
            $table->string('unit', 20)->nullable()->comment('Satuan pemotongan');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 3. Relasi Menu ke Modifier Group (Pivot)
        Schema::create('menu_modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['menu_id', 'modifier_group_id']);
        });

        // 4. Snapshot data Modifier yang dipilih saat Transaksi Kasir
        Schema::create('transaction_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('modifier_option_id')->nullable()->constrained('modifier_options')->nullOnDelete();
            $table->string('group_name');
            $table->string('name');
            $table->decimal('price', 15, 2)->default(0);
            $table->foreignId('ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();
            $table->decimal('qty', 15, 3)->default(0);
            $table->string('unit', 20)->nullable();
            $table->decimal('total_price', 15, 2)->default(0)->comment('price * transaction.qty');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_modifiers');
        Schema::dropIfExists('menu_modifier_groups');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifier_groups');
    }
};
